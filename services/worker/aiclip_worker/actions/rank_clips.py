"""Rank clips action with strict validation and privacy-safe transport."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from typing import Any

from aiclip_worker.ranking import (
    CrossEncoderRankingProvider,
    FakeRankingProvider,
    RankingInput,
    Recommendation,
)


MAX_INPUT_BYTES = 8 * 1024 * 1024  # 8MB


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


def _validate_candidates(candidates: list[dict], duration_ms: int) -> None:
    """Strictly validate candidate list."""
    if not isinstance(candidates, list):
        raise ValueError("candidates must be a list")

    k = len(candidates)
    seen_indices = set()
    seen_ranks = set()

    for i, candidate in enumerate(candidates):
        if not isinstance(candidate, dict):
            raise ValueError(f"candidate {i} must be an object")

        # Required fields with strict types (no bool for int fields)
        for field in ("index", "start_ms", "end_ms", "rank"):
            if field not in candidate:
                raise ValueError(f"candidate {i} missing required field: {field}")
            value = candidate[field]
            if isinstance(value, bool) or not isinstance(value, int):
                raise ValueError(f"candidate {i}.{field} must be an integer, not {type(value).__name__}")

        index = candidate["index"]
        start_ms = candidate["start_ms"]
        end_ms = candidate["end_ms"]
        rank = candidate["rank"]

        # Index validation
        if index < 0 or index >= k:
            raise ValueError(f"candidate {i}.index out of range: {index}")
        if index in seen_indices:
            raise ValueError(f"duplicate candidate index: {index}")
        if index != i:
            raise ValueError(f"candidate index must be sequential 0..K-1, got {index} at position {i}")
        seen_indices.add(index)

        # Timing validation
        if start_ms < 0 or start_ms > duration_ms:
            raise ValueError(f"candidate {i}.start_ms out of range: {start_ms}")
        if end_ms <= start_ms or end_ms > duration_ms:
            raise ValueError(f"candidate {i}.end_ms invalid: {end_ms}")

        # Rank validation
        if rank < 1 or rank > k:
            raise ValueError(f"candidate {i}.rank out of range 1..{k}: {rank}")
        if rank in seen_ranks:
            raise ValueError(f"duplicate candidate rank: {rank}")
        seen_ranks.add(rank)

        # Transcript text (required field, may be empty string)
        if "transcript_text" not in candidate:
            raise ValueError(f"candidate {i} missing required field: transcript_text")
        if not isinstance(candidate["transcript_text"], str):
            raise ValueError(f"candidate {i}.transcript_text must be a string")


def _validate_configuration(configuration: dict) -> str:
    """Validate configuration and return prototype_query."""
    if not isinstance(configuration, dict):
        raise ValueError("configuration must be an object")

    if "prototype_query" not in configuration:
        raise ValueError("configuration missing required field: prototype_query")

    prototype_query = configuration["prototype_query"]
    if not isinstance(prototype_query, str):
        raise ValueError("configuration.prototype_query must be a string")
    if not prototype_query:
        raise ValueError("configuration.prototype_query must not be empty")

    # Reject unknown fields in configuration
    allowed_config_fields = {"prototype_query"}
    unknown = set(configuration.keys()) - allowed_config_fields
    if unknown:
        raise ValueError(f"configuration contains unknown fields: {sorted(unknown)}")

    return prototype_query


def _transcript_text_hash(text: str) -> str:
    """Compute SHA256 hash of transcript text for privacy-safe snapshot."""
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def rank_clips(contract: dict) -> dict[str, Any]:
    """Rank clips from contract."""
    try:
        # Validate required top-level fields
        if not isinstance(contract, dict):
            return error("invalid_contract")

        if contract.get("version") != "1.0.0":
            return error("invalid_contract")

        if contract.get("action") != "rank_clips":
            return error("invalid_contract")

        # Validate media
        media = contract.get("media")
        if not isinstance(media, dict) or "duration_ms" not in media:
            return error("invalid_contract")
        duration_ms = media["duration_ms"]
        if not isinstance(duration_ms, int) or duration_ms <= 0:
            return error("invalid_contract")

        # Validate candidates
        candidates = contract.get("candidates")
        try:
            _validate_candidates(candidates, duration_ms)
        except ValueError:
            return error("invalid_contract")

        # Validate configuration
        configuration = contract.get("configuration")
        try:
            prototype_query = _validate_configuration(configuration)
        except ValueError:
            return error("invalid_contract")

        # Reject unknown top-level fields (only rank_clips fields allowed)
        allowed_top_level = {"version", "action", "media", "candidates", "configuration"}
        unknown = set(contract.keys()) - allowed_top_level
        if unknown:
            return error("invalid_contract")

        # Choose provider (Fake for CI, CrossEncoder for production)
        # In CI, FAKE_RANKING_PROVIDER env var can be set to use fake provider
        import os
        if os.environ.get("FAKE_RANKING_PROVIDER") == "1":
            provider = FakeRankingProvider()
        else:
            provider = CrossEncoderRankingProvider()

        # Build ranking input
        ranking_input = RankingInput(
            candidates=candidates,
            prototype_query=prototype_query,
        )

        # Execute ranking
        output = provider.rank(ranking_input)

        # Build response
        recommendations_json = [
            {
                "m4_candidate_index": rec.m4_candidate_index,
                "semantic_score": rec.semantic_score,
                "combined_rank": rec.combined_rank,
            }
            for rec in output.recommendations
        ]

        # Spec-fixed provenance parameters (constants from the ranking algorithm contract).
        # transcript_used is computed from the input, not the provider, so PHP can
        # independently rederive the same value from the request.
        input_transcript_used = any(c.get("transcript_text", "") for c in candidates)

        parameters = {
            "prototype_query": prototype_query,
            "model_id": "cross-encoder/ms-marco-MiniLM-L-6-v2",
            "model_revision": "main",
            "provider_name": "cross_encoder_ranking_provider",
            "transcript_used": input_transcript_used,
            "normalization": "sigmoid",
            "score_scale": 1.0,
            "tie_break": "m4_rank_then_chronological",
        }

        # Build input snapshot (for provenance, with transcript text hashes only)
        input_snapshot = {
            "duration_ms": duration_ms,
            "candidates": [
                {
                    "index": c["index"],
                    "start_ms": c["start_ms"],
                    "end_ms": c["end_ms"],
                    "rank": c["rank"],
                    "transcript_text_hash": _transcript_text_hash(c.get("transcript_text", "")),
                }
                for c in candidates
            ],
            "transcript_used": input_transcript_used,
            "prototype_query": prototype_query,
        }

        return {
            "status": "success",
            "ranking": {
                "algorithm": "cross_encoder_reranker",
                "algorithm_version": "1.0.0",
                "parameters": parameters,
                "recommendations": recommendations_json,
            },
        }

    except (ValueError, TypeError, RecursionError, OverflowError):
        return error("invalid_contract")
    except Exception:
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
        raise ValueError("Argument parsing error")


def run_cli(argv: list[str]) -> int:
    """CLI entry point for rank-clips subcommand."""
    try:
        parser = _Parser(prog="aiclip_worker rank-clips", add_help=False)
        source = parser.add_mutually_exclusive_group()
        source.add_argument("--contract-json")
        source.add_argument("--contract-file")
        args = parser.parse_args(argv)

        if args.contract_json is not None:
            raw = args.contract_json.encode("utf-8")
        elif args.contract_file is not None:
            with open(args.contract_file, "rb") as stream:
                raw = stream.read(MAX_INPUT_BYTES + 1)
        elif not sys.stdin.isatty():
            raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
        else:
            raise ValueError("No contract input")

        if len(raw) > MAX_INPUT_BYTES:
            raise ValueError("Input too large")

        contract = json.loads(
            raw.decode("utf-8"),
            parse_constant=_reject_constant,
            parse_float=_finite_float,
            object_pairs_hook=_unique_object,
        )

        result = rank_clips(contract)

    except (OSError, ValueError, TypeError, UnicodeError, RecursionError):
        result = error("invalid_contract")

    try:
        output = json.dumps(result, allow_nan=False, separators=(",", ":"))
    except (ValueError, TypeError):
        result = error("ranking_failed")
        output = json.dumps(result, allow_nan=False)

    sys.stdout.write(output)
    return 0 if result["status"] == "success" else (2 if result["code"] == "invalid_contract" else 1)