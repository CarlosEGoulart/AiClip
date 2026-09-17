"""CLI entry point for the AiClip media processing worker."""

from __future__ import annotations

import argparse
import json
import sys
from typing import Any

from aiclip_worker.actions.extract_audio import extract_audio
from aiclip_worker.actions.probe import probe_media
from aiclip_worker.actions.transcribe import transcribe
from aiclip_worker.contracts import validate_contract


def _read_contract_json(args: argparse.Namespace) -> dict[str, Any] | None:
    """Read contract JSON from --contract-json or --contract-file."""
    if args.contract_json:
        try:
            return json.loads(args.contract_json)
        except json.JSONDecodeError:
            return None

    if args.contract_file:
        try:
            with open(args.contract_file) as f:
                return json.load(f)
        except (FileNotFoundError, json.JSONDecodeError):
            return None

    # Try stdin if not a TTY
    if not sys.stdin.isatty():
        try:
            return json.loads(sys.stdin.read())
        except json.JSONDecodeError:
            return None

    return None


def _handle_probe(args: argparse.Namespace) -> int:
    """Handle the probe subcommand. Returns exit code."""
    contract = _read_contract_json(args)

    if contract is None:
        error_output = {
            "status": "error",
            "error": "Invalid or missing contract JSON",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Validate contract
    is_valid, error_msg = validate_contract(contract)
    if not is_valid:
        error_output = {
            "status": "error",
            "error": error_msg,
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Probe the media
    result = probe_media(contract)
    json.dump(result, sys.stdout)

    if result["status"] == "success":
        return 0
    return 1


def _handle_extract_audio(args: argparse.Namespace) -> int:
    """Handle the extract-audio subcommand. Returns exit code."""
    contract = _read_contract_json(args)

    if contract is None:
        error_output = {
            "status": "error",
            "error": "Invalid or missing contract JSON",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Validate contract
    is_valid, error_msg = validate_contract(contract)
    if not is_valid:
        error_output = {
            "status": "error",
            "error": error_msg,
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Check that action is extract_audio
    action = contract.get("action", "probe")
    if action != "extract_audio":
        error_output = {
            "status": "error",
            "error": f"Expected action 'extract_audio', got '{action}'",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Check that output_storage is present
    if "output_storage" not in contract:
        error_output = {
            "status": "error",
            "error": "Missing required field 'output_storage' for extract_audio action",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Extract audio
    result = extract_audio(contract)
    json.dump(result, sys.stdout)

    if result["status"] == "success":
        return 0
    return 1


def _handle_transcribe(args: argparse.Namespace) -> int:
    """Handle the transcribe subcommand. Returns exit code."""
    contract = _read_contract_json(args)

    if contract is None:
        error_output = {
            "status": "error",
            "error": "Invalid or missing contract JSON",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Validate contract
    is_valid, error_msg = validate_contract(contract)
    if not is_valid:
        error_output = {
            "status": "error",
            "error": error_msg,
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Check that action is transcribe
    action = contract.get("action", "probe")
    if action != "transcribe":
        error_output = {
            "status": "error",
            "error": f"Expected action 'transcribe', got '{action}'",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Check that derived_asset_id is present
    if "derived_asset_id" not in contract:
        error_output = {
            "status": "error",
            "error": "Missing required field 'derived_asset_id' for transcribe action",
            "stderr": "",
        }
        json.dump(error_output, sys.stdout)
        return 2

    # Transcribe
    result = transcribe(contract)
    json.dump(result, sys.stdout)

    if result["status"] == "success":
        return 0
    return 1


def main(argv: list[str] | None = None) -> int:
    """Main CLI entry point. Returns exit code."""
    parser = argparse.ArgumentParser(
        prog="aiclip_worker",
        description="AiClip media processing worker",
    )
    subparsers = parser.add_subparsers(dest="command", help="Available commands")

    probe_parser = subparsers.add_parser("probe", help="Probe media file metadata")
    probe_parser.add_argument(
        "--contract-json",
        type=str,
        help="Contract JSON string",
    )
    probe_parser.add_argument(
        "--contract-file",
        type=str,
        help="Path to contract JSON file",
    )

    extract_audio_parser = subparsers.add_parser("extract-audio", help="Extract and normalize audio")
    extract_audio_parser.add_argument(
        "--contract-json",
        type=str,
        help="Contract JSON string",
    )
    extract_audio_parser.add_argument(
        "--contract-file",
        type=str,
        help="Path to contract JSON file",
    )

    transcribe_parser = subparsers.add_parser("transcribe", help="Transcribe audio to text")
    transcribe_parser.add_argument(
        "--contract-json",
        type=str,
        help="Contract JSON string",
    )
    transcribe_parser.add_argument(
        "--contract-file",
        type=str,
        help="Path to contract JSON file",
    )

    args = parser.parse_args(argv)

    if args.command == "probe":
        return _handle_probe(args)
    elif args.command == "extract-audio":
        return _handle_extract_audio(args)
    elif args.command == "transcribe":
        return _handle_transcribe(args)

    parser.print_help()
    return 2


if __name__ == "__main__":
    sys.exit(main())
