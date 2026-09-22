"""Privacy-safe clip analysis action and bounded JSON CLI transport."""

from __future__ import annotations

import argparse
import json
import math
import sys

from aiclip_worker.clip_analysis import (
    MAX_INPUT_BYTES, ClipAnalysisInput, ClipAnalysisResult, ClipValidationError,
    DeterministicClipCandidateAnalyzer,
)


def error(code: str) -> dict:
    return {"status": "error", "code": code,
            "error": "Invalid clip analysis contract" if code == "invalid_contract" else "Clip analysis failed",
            "stderr": ""}


def analyze_clips(contract) -> dict:
    try:
        data, configuration = ClipAnalysisInput.from_contract(contract)
    except (ClipValidationError, ValueError, TypeError, RecursionError):
        return error("invalid_contract")
    try:
        result = DeterministicClipCandidateAnalyzer().analyze(data, configuration)
        validated = ClipAnalysisResult.from_dict(result.to_dict(), data, configuration)
        return {"status": "success", "analysis": validated.to_dict()}
    except Exception:
        return error("analysis_failed")


def _reject_constant(value):
    raise ClipValidationError()


def _finite_float(value):
    result = float(value)
    if not math.isfinite(result):
        raise ClipValidationError()
    return result


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ClipValidationError()
        result[key] = value
    return result


class _Parser(argparse.ArgumentParser):
    def error(self, message):
        raise ClipValidationError()


def run_cli(argv: list[str]) -> int:
    try:
        parser = _Parser(prog="aiclip_worker analyze-clips", add_help=False)
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
            raise ClipValidationError()
        if len(raw) > MAX_INPUT_BYTES:
            raise ClipValidationError()
        contract = json.loads(raw.decode("utf-8"), parse_constant=_reject_constant,
                              parse_float=_finite_float, object_pairs_hook=_unique_object)
        result = analyze_clips(contract)
    except (OSError, ValueError, TypeError, UnicodeError, RecursionError):
        result = error("invalid_contract")
    try:
        output = json.dumps(result, allow_nan=False, separators=(",", ":"))
    except (ValueError, TypeError):
        result = error("analysis_failed")
        output = json.dumps(result, allow_nan=False)
    sys.stdout.write(output)
    return 0 if result["status"] == "success" else (2 if result["code"] == "invalid_contract" else 1)
