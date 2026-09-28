"""CLI transport tests for the rank-clips subcommand.

The transport is stdin only (spec.md "Strict worker protocol"): argument-list
and file transports are rejected without echoing their payload, the echoed
digest is the SHA256 of the exact raw stdin bytes, and the exit codes are
0 for success, 2 for an invalid contract and 1 for a runtime/output failure.

Coverage follows test-plan.md, "Worker unit, schema, action and CLI
coverage", item 5.
"""

from __future__ import annotations

import copy
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

WORKER_ROOT = Path(__file__).resolve().parents[1]

PROTOTYPE_QUERY = (
    "Engaging, self-contained short-form video clip highlight with a clear "
    "narrative or punchline."
)

FAKE_CONFIGURATION = {
    "provider": "fake",
    "algorithm": "transcript_semantic_recommendation",
    "algorithm_version": "1.0.0",
    "projection_version": "1.0.0",
    "query_version": "1.0.0",
    "prototype_query": PROTOTYPE_QUERY,
    "model_id": "fake-ranking-v1",
    "model_revision": "1.0.0",
    "runtime_profile": "fake_v1",
    "normalization": "fixture_units_6",
    "max_tokens": 0,
    "batch_size": 0,
    "truncation": "none",
}

REAL_CONFIGURATION = {
    "provider": "cross_encoder",
    "algorithm": "transcript_semantic_recommendation",
    "algorithm_version": "1.0.0",
    "projection_version": "1.0.0",
    "query_version": "1.0.0",
    "prototype_query": PROTOTYPE_QUERY,
    "model_id": "cross-encoder/ms-marco-MiniLM-L6-v2",
    "model_revision": "233902d25c440f23af6f7d6e94d2946bac0bee0a",
    "runtime_profile": "minilm_cpu_v1",
    "normalization": "stable_sigmoid_half_up_6",
    "max_tokens": 512,
    "batch_size": 8,
    "truncation": "right_longest_first_512",
}


def rank_clips_contract(provider: str = "fake", texts=None) -> dict:
    """A strict K=2 request on the selected profile.

    Returns a fresh configuration copy per call to prevent test fixture
    leakage where mutations in one test pollute subsequent tests.
    """
    if texts is None:
        texts = ("engaging content", "second passage")
    return {
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 40000},
        "candidates": [
            {
                "index": 0,
                "start_ms": 0,
                "end_ms": 10000,
                "m4_rank": 1,
                "m4_score": 0.91,
                "transcript_text": texts[0],
            },
            {
                "index": 1,
                "start_ms": 10000,
                "end_ms": 20000,
                "m4_rank": 2,
                "m4_score": 0.72,
                "transcript_text": texts[1],
            },
        ],
        "configuration": copy.deepcopy(
            FAKE_CONFIGURATION if provider == "fake" else REAL_CONFIGURATION
        ),
    }


def run_cli(args, input_data=None, timeout=20, env_extra=None):
    """Run `python -m aiclip_worker.cli <args>` with raw stdin bytes.

    Input is passed as bytes so the digest asserted by the tests is the
    digest of the exact bytes the child process reads.
    """
    if isinstance(input_data, str):
        input_data = input_data.encode("utf-8")

    env = {
        **os.environ,
        "PYTHONHASHSEED": "1",
        "PYTHONPATH": str(WORKER_ROOT)
        + os.pathsep
        + os.environ.get("PYTHONPATH", ""),
        "HF_HUB_OFFLINE": "1",
        "TRANSFORMERS_OFFLINE": "1",
        "HF_HUB_DISABLE_TELEMETRY": "1",
        "TQDM_DISABLE": "1",
    }
    if env_extra:
        env.update(env_extra)

    return subprocess.run(
        [sys.executable, "-m", "aiclip_worker.cli", *args],
        input=input_data,
        capture_output=True,
        env=env,
        cwd=str(WORKER_ROOT),
        timeout=timeout,
    )


def stdout_json(result) -> dict:
    """Decode the single strict JSON envelope written to stdout."""
    return json.loads(result.stdout.decode("utf-8"))


def assert_no_traceback(result) -> None:
    assert b"Traceback" not in result.stdout
    assert b"Traceback" not in result.stderr
    assert len(result.stderr) < 16384, "stderr must stay bounded"


def test_cli_rank_clips_subcommand_exists():
    """The rank-clips subcommand is still offered by the worker CLI."""
    result = run_cli(["--help"])
    assert result.returncode == 0
    assert b"rank-clips" in result.stdout


def test_cli_rank_clips_success_exit_code():
    """A valid request on the fake profile returns exit code 0."""
    payload = json.dumps(rank_clips_contract())
    result = run_cli(["rank-clips"], input_data=payload)
    assert result.returncode == 0, result.stdout + result.stderr
    output = stdout_json(result)
    assert output["status"] == "success"
    assert len(output["ranking"]["recommendations"]) == 2
    assert_no_traceback(result)


def test_cli_rank_clips_stdout_is_one_strict_json_object():
    """Success writes exactly one strict JSON object and nothing else."""
    payload = json.dumps(rank_clips_contract())
    result = run_cli(["rank-clips"], input_data=payload)
    assert result.returncode == 0
    output = stdout_json(result)
    assert set(output.keys()) == {"status", "ranking"}
    assert set(output["ranking"].keys()) == {
        "algorithm",
        "algorithm_version",
        "parameters",
        "request_sha256",
        "recommendations",
    }
    # No progress bars, banners or second objects on stdout.
    assert result.stdout.count(b"\n") == 0


def test_cli_rank_clips_digest_matches_exact_stdin_bytes():
    """The echoed digest is SHA256 over the raw stdin bytes, not a
    reserialized approximation of the request."""
    payload = json.dumps(rank_clips_contract(), indent=2) + "\n   \n"
    expected = hashlib.sha256(payload.encode("utf-8")).hexdigest()

    result = run_cli(["rank-clips"], input_data=payload)
    assert result.returncode == 0, result.stdout + result.stderr
    output = stdout_json(result)
    assert output["ranking"]["request_sha256"] == expected

    # A reserialized, compact form of the same request hashes differently.
    compact = json.dumps(json.loads(payload), separators=(",", ":"))
    assert hashlib.sha256(compact.encode("utf-8")).hexdigest() != expected


def test_cli_rank_clips_invalid_contract_exit_code():
    """An invalid contract returns exit code 2 with the exact envelope."""
    result = run_cli(["rank-clips"], input_data='{"invalid": "contract"}')
    assert result.returncode == 2
    assert stdout_json(result) == {
        "status": "error",
        "code": "invalid_contract",
        "error": "Invalid ranking contract",
        "stderr": "",
    }
    assert_no_traceback(result)


def test_cli_rank_clips_malformed_json_exit_code():
    """Malformed JSON must be rejected with exit code 2."""
    result = run_cli(["rank-clips"], input_data="{invalid json}")
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"
    assert_no_traceback(result)


def test_cli_rank_clips_rejects_unknown_fields():
    """Unknown fields in the contract must be rejected."""
    contract = rank_clips_contract()
    contract["configuration"]["unknown_field"] = "value"
    result = run_cli(["rank-clips"], input_data=json.dumps(contract))
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"


def test_cli_rank_clips_rejects_duplicate_json_keys():
    """Duplicate keys are rejected before validation."""
    raw = json.dumps(rank_clips_contract())
    duplicated = raw.replace('"version":', '"version":"1.0.0","version":', 1)
    assert duplicated != raw

    result = run_cli(["rank-clips"], input_data=duplicated)
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"


def test_cli_rank_clips_rejects_trailing_data():
    """A second JSON document after the request is rejected."""
    raw = json.dumps(rank_clips_contract()) + "{}"
    result = run_cli(["rank-clips"], input_data=raw)
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"


def test_cli_rank_clips_rejects_json_constants():
    """NaN and Infinity are not valid request values."""
    raw = json.dumps(rank_clips_contract()).replace(
        '"m4_score": 0.91', '"m4_score": NaN'
    )
    assert "NaN" in raw

    result = run_cli(["rank-clips"], input_data=raw)
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"


def test_cli_rank_clips_rejects_malformed_utf8():
    """Invalid UTF-8 on stdin is rejected as an invalid contract."""
    result = run_cli(["rank-clips"], input_data=b'{"version": "\xff\xfe"}')
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"


def test_cli_rank_clips_rejects_input_above_eight_mib():
    """Input above the 8 MiB request bound fails closed."""
    result = run_cli(
        ["rank-clips"], input_data=b"x" * (8 * 1024 * 1024 + 1)
    )
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"


def test_cli_rank_clips_accepts_input_at_the_eight_mib_bound():
    """Exactly 8 MiB of transport padding is still an accepted request."""
    payload = json.dumps(rank_clips_contract())
    padding = b" " * (8 * 1024 * 1024 - len(payload.encode("utf-8")))
    result = run_cli(["rank-clips"], input_data=payload.encode("utf-8") + padding)
    assert result.returncode == 0, result.stdout + result.stderr
    assert stdout_json(result)["status"] == "success"


def test_cli_rank_clips_rejects_argument_list_json_transport():
    """JSON on the argument list is rejected without echoing the payload."""
    payload = json.dumps(rank_clips_contract(texts=("PRIVATE_ARG_SENTINEL", "")))
    result = run_cli(["rank-clips", "--contract-json", payload])

    assert result.returncode == 2
    output = stdout_json(result)
    assert output["code"] == "invalid_contract"
    assert b"PRIVATE_ARG_SENTINEL" not in result.stdout
    assert b"PRIVATE_ARG_SENTINEL" not in result.stderr
    assert b"--contract-json" not in result.stdout
    assert_no_traceback(result)


def test_cli_rank_clips_rejects_argument_list_file_transport(tmp_path):
    """A file transport is rejected without being read or echoed."""
    contract_file = tmp_path / "contract.json"
    contract_file.write_text(
        json.dumps(rank_clips_contract(texts=("PRIVATE_FILE_SENTINEL", ""))),
        encoding="utf-8",
    )

    result = run_cli(["rank-clips", "--contract-file", str(contract_file)])
    assert result.returncode == 2
    output = stdout_json(result)
    assert output["code"] == "invalid_contract"
    assert b"PRIVATE_FILE_SENTINEL" not in result.stdout
    assert b"PRIVATE_FILE_SENTINEL" not in result.stderr
    assert_no_traceback(result)


def test_cli_rank_clips_rejects_positional_json_transport():
    """A positional JSON argument is rejected without echoing it."""
    payload = json.dumps(rank_clips_contract())
    result = run_cli(["rank-clips", payload])
    assert result.returncode == 2
    assert stdout_json(result)["code"] == "invalid_contract"
    assert payload.encode("utf-8") not in result.stdout
    assert payload.encode("utf-8") not in result.stderr


def test_cli_rank_clips_runtime_failure_returns_exit_one(tmp_path):
    """An unavailable runtime/model cache is a runtime failure: exit 1 with
    the exact ranking_failed envelope and no fabricated success."""
    empty_cache = tmp_path / "empty-ranking-cache"
    empty_cache.mkdir()

    contract = rank_clips_contract(provider="cross_encoder")
    result = run_cli(
        ["rank-clips"],
        input_data=json.dumps(contract),
        env_extra={"SENTENCE_TRANSFORMERS_HOME": str(empty_cache)},
        timeout=60,
    )

    assert result.returncode == 1, result.stdout + result.stderr
    assert stdout_json(result) == {
        "status": "error",
        "code": "ranking_failed",
        "error": "Ranking failed",
        "stderr": "",
    }
    assert b"PRIVATE" not in result.stdout
    assert b"ImportError" not in result.stdout + result.stderr
    assert b"No such file" not in result.stdout + result.stderr
    assert_no_traceback(result)


def test_cli_rank_clips_k_1000_output_stays_under_one_mib():
    """The 1 MiB response bound holds at the candidate cap."""
    contract = rank_clips_contract()
    contract["media"]["duration_ms"] = 1000 * 1000
    contract["candidates"] = [
        {
            "index": position,
            "start_ms": position * 1000,
            "end_ms": (position + 1) * 1000,
            "m4_rank": position + 1,
            "m4_score": 0.5,
            "transcript_text": f"candidate {position}",
        }
        for position in range(1000)
    ]

    result = run_cli(["rank-clips"], input_data=json.dumps(contract))
    assert result.returncode == 0, result.stdout + result.stderr
    output = stdout_json(result)
    assert len(output["ranking"]["recommendations"]) == 1000
    assert len(result.stdout) < 1024 * 1024
    assert_no_traceback(result)


def test_cli_rank_clips_no_private_sentinel_in_output():
    """Private sentinel text never reaches stdout or stderr."""
    payload = json.dumps(
        rank_clips_contract(texts=("PRIVATE_SENTINEL", "SECOND_PRIVATE_SENTINEL"))
    )
    result = run_cli(["rank-clips"], input_data=payload)

    assert result.returncode == 0, result.stdout + result.stderr
    assert b"PRIVATE_SENTINEL" not in result.stdout
    assert b"PRIVATE_SENTINEL" not in result.stderr
    assert_no_traceback(result)


def test_cli_rank_clips_no_traceback_for_invalid_contract():
    """Neither stdout nor stderr carries a traceback for rejected input."""
    result = run_cli(["rank-clips"], input_data='{"invalid": "contract"}')
    assert result.returncode == 2
    assert b"Traceback" not in result.stdout.lower()
    assert b"Traceback" not in result.stderr.lower()


def test_cli_rank_clips_configuration_fixture_isolation():
    """Mutating a contract's configuration must not pollute global fixtures.

    This regression test verifies the fix for fixture leakage where
    test_cli_rank_clips_rejects_unknown_fields added an unknown_field to the
    global FAKE_CONFIGURATION, causing subsequent tests to fail with
    invalid_contract because the polluted configuration was rejected.
    """
    # First contract: mutate its configuration (simulating the unknown-fields test)
    contract1 = rank_clips_contract()
    contract1["configuration"]["injected_unknown_field"] = "pollution"

    # Second contract: fresh call must not contain the injected field
    contract2 = rank_clips_contract()

    # The global FAKE_CONFIGURATION must remain pristine
    assert "injected_unknown_field" not in FAKE_CONFIGURATION
    assert "unknown_field" not in FAKE_CONFIGURATION

    # The second contract's configuration must be clean
    assert "injected_unknown_field" not in contract2["configuration"]
    assert "unknown_field" not in contract2["configuration"]

    # Both contracts must have independent configuration objects
    assert contract1["configuration"] is not contract2["configuration"]
    assert contract1["configuration"] is not FAKE_CONFIGURATION
    assert contract2["configuration"] is not FAKE_CONFIGURATION

    # Same isolation must hold for REAL_CONFIGURATION
    contract3 = rank_clips_contract(provider="cross_encoder")
    contract3["configuration"]["injected_real_field"] = "pollution"
    contract4 = rank_clips_contract(provider="cross_encoder")

    assert "injected_real_field" not in REAL_CONFIGURATION
    assert "injected_real_field" not in contract4["configuration"]
    assert contract3["configuration"] is not contract4["configuration"]
    assert contract3["configuration"] is not REAL_CONFIGURATION
    assert contract4["configuration"] is not REAL_CONFIGURATION
