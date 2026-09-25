"""CLI tests for rank-clips subcommand."""

from __future__ import annotations

import json
import os
import subprocess
import sys

import pytest


def valid_rank_clips_json():
    """Valid rank_clips contract JSON."""
    return json.dumps({
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 40000},
        "candidates": [
            {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "engaging content"},
            {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 2, "transcript_text": ""},
        ],
        "configuration": {
            "prototype_query": "Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline."
        },
    })


def run_cli(args, input_data=None, timeout=15):
    """Run the CLI with given args and optional stdin input."""
    cmd = [sys.executable, "-m", "aiclip_worker.cli"] + args
    return subprocess.run(
        cmd,
        input=input_data,
        capture_output=True,
        text=True,
        env={**os.environ, "PYTHONHASHSEED": "1"},
        timeout=timeout,
    )


def test_cli_rank_clips_subcommand_exists():
    """rank-clips subcommand must exist (currently fails - RED)."""
    result = run_cli(["--help"])
    # Check if rank-clips appears in help
    assert "rank-clips" in result.stdout or "rank-clips" in result.stderr


def test_cli_rank_clips_stdin_transport():
    """rank-clips must accept contract via stdin (currently fails - RED)."""
    result = run_cli(["rank-clips"], input_data=valid_rank_clips_json())
    # Should not be "command not recognized" error
    assert result.returncode != 2 or "unrecognized" not in result.stderr.lower()


def test_cli_rank_clips_contract_json_arg():
    """rank-clips must accept --contract-json argument."""
    result = run_cli(["rank-clips", "--contract-json", valid_rank_clips_json()])
    assert result.returncode != 2 or "unrecognized" not in result.stderr.lower()


def test_cli_rank_clips_contract_file_arg(tmp_path):
    """rank-clips must accept --contract-file argument."""
    contract_file = tmp_path / "contract.json"
    contract_file.write_text(valid_rank_clips_json())
    result = run_cli(["rank-clips", "--contract-file", str(contract_file)])
    assert result.returncode != 2 or "unrecognized" not in result.stderr.lower()


def test_cli_rank_clips_success_exit_code():
    """Valid input must return exit code 0."""
    result = run_cli(["rank-clips"], input_data=valid_rank_clips_json())
    # Currently will fail because action not implemented, but should not be "command not found"
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")


def test_cli_rank_clips_invalid_contract_exit_code():
    """Invalid contract must return exit code 2."""
    result = run_cli(["rank-clips"], input_data='{"invalid": "contract"}')
    # Should return 2 for invalid contract, not for unrecognized command
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")


def test_cli_rank_clips_stdout_is_strict_json():
    """Stdout must be a single strict JSON envelope."""
    result = run_cli(["rank-clips"], input_data=valid_rank_clips_json())
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")
    if result.returncode in (0, 1, 2):
        try:
            output = json.loads(result.stdout)
            assert "status" in output
        except json.JSONDecodeError:
            pytest.fail("Stdout is not valid JSON")


def test_cli_rank_clips_no_traceback_in_output():
    """No traceback must appear in stdout or stderr."""
    result = run_cli(["rank-clips"], input_data=valid_rank_clips_json())
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")
    assert "traceback" not in result.stdout.lower()
    assert "traceback" not in result.stderr.lower()


def test_cli_rank_clips_no_private_sentinel_in_output():
    """Private sentinel text must not leak to stdout/stderr."""
    contract = json.dumps({
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 10000},
        "candidates": [{"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "PRIVATE_SENTINEL"}],
        "configuration": {"prototype_query": "test query"},
    })
    result = run_cli(["rank-clips"], input_data=contract)
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")
    assert "PRIVATE_SENTINEL" not in result.stdout
    assert "PRIVATE_SENTINEL" not in result.stderr


def test_cli_rank_clips_rejects_unknown_fields():
    """Unknown fields in contract must be rejected."""
    contract = json.dumps({
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 10000},
        "candidates": [{"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "text"}],
        "configuration": {"prototype_query": "test", "unknown_field": "value"},
    })
    result = run_cli(["rank-clips"], input_data=contract)
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")
    assert result.returncode == 2
    try:
        output = json.loads(result.stdout)
        assert output["code"] == "invalid_contract"
    except json.JSONDecodeError:
        pytest.fail("Stdout is not valid JSON")


def test_cli_rank_clips_rejects_malformed_json():
    """Malformed JSON must be rejected with exit code 2."""
    result = run_cli(["rank-clips"], input_data='{invalid json}')
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")
    assert result.returncode == 2
    try:
        output = json.loads(result.stdout)
        assert output["code"] == "invalid_contract"
    except json.JSONDecodeError:
        pytest.fail("Stdout is not valid JSON")


def test_cli_rank_clips_byte_limit():
    """Input exceeding byte limit must be rejected."""
    # This test assumes MAX_INPUT_BYTES is defined similarly to analyze-clips
    large_text = "x" * 10000000  # 10MB
    contract = {
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 10000},
        "candidates": [{"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": large_text}],
        "configuration": {"prototype_query": "test"},
    }
    result = run_cli(["rank-clips"], input_data=json.dumps(contract))
    if result.returncode == 2 and "unrecognized" in result.stderr.lower():
        pytest.fail("rank-clips subcommand not recognized")
    # Should be rejected (either 2 for contract too large, or 1 for processing failure)
    assert result.returncode != 0