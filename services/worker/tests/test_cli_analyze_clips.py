"""Real CLI protocol and metadata-only action privacy checks."""

import json
import os
import subprocess
import sys
from copy import deepcopy

import pytest

from aiclip_worker.actions.analyze_clips import analyze_clips
from aiclip_worker.clip_analysis import DeterministicClipCandidateAnalyzer
from tests.test_clip_analysis import golden_contract


def cli(raw, *args, seed="1"):
    return subprocess.run(
        [sys.executable, "-m", "aiclip_worker.cli", "analyze-clips", *args],
        input=raw, capture_output=True, text=True,
        env={**os.environ, "PYTHONHASHSEED": seed}, timeout=15,
    )


def test_stdin_cli_is_deterministic_across_process_seeds():
    raw = json.dumps(golden_contract())
    first, second = cli(raw), cli(raw, seed="47")
    assert first.returncode == second.returncode == 0
    assert first.stderr == second.stderr == ""
    assert json.loads(first.stdout) == json.loads(second.stdout) == analyze_clips(golden_contract())


@pytest.mark.parametrize("raw", ["", "null", "[]", "3", "true", "{", "NaN", "Infinity", "1e9999",
                                    '{"action":"analyze_clips","private":"PRIVATE_SENTINEL"}'])
def test_invalid_json_shapes_are_sanitized(raw):
    result = cli(raw)
    assert result.returncode == 2
    assert result.stderr == ""
    assert json.loads(result.stdout) == {
        "status": "error", "code": "invalid_contract",
        "error": "Invalid clip analysis contract", "stderr": "",
    }


def test_all_input_transports_and_byte_limit(tmp_path):
    raw = json.dumps(golden_contract())
    path = tmp_path / "contract.json"
    path.write_text(raw)
    assert cli("", "--contract-file", str(path)).returncode == 0
    assert cli("", "--contract-json", raw).returncode == 0
    assert cli("", "--contract-file", str(tmp_path / "PRIVATE_SENTINEL")).returncode == 2
    exact = raw + " " * (8388608 - len(raw.encode()))
    assert cli(exact).returncode == 0
    oversized = cli(exact + " ")
    assert oversized.returncode == 2
    assert "PRIVATE_SENTINEL" not in oversized.stdout + oversized.stderr


def test_action_does_no_media_network_database_or_child_process_work(monkeypatch):
    def forbidden(*args, **kwargs):
        raise AssertionError("Metadata action attempted external work")
    monkeypatch.setattr("socket.socket", forbidden)
    monkeypatch.setattr("subprocess.Popen", forbidden)
    monkeypatch.setattr("sqlite3.connect", forbidden)
    monkeypatch.setattr("builtins.open", forbidden)
    result = analyze_clips(golden_contract())
    assert result["status"] == "success"
    assert result["analysis"]["candidates"][0]["score"] == 1


def test_action_sanitizes_runtime_errors_and_malformed_results(monkeypatch, capsys, caplog):
    def fail(*args):
        raise RuntimeError("PRIVATE_SENTINEL")
    monkeypatch.setattr(DeterministicClipCandidateAnalyzer, "analyze", fail)
    assert analyze_clips(golden_contract()) == {
        "status": "error", "code": "analysis_failed", "error": "Clip analysis failed", "stderr": "",
    }
    assert capsys.readouterr() == ("", "")
    assert "PRIVATE_SENTINEL" not in caplog.text


def test_required_and_unknown_fields_fail_recursively():
    base = golden_contract()
    paths = [(), ("media",), ("configuration",), ("configuration", "weights"), ("scenes", 0)]
    for path in paths:
        original = base
        for key in path:
            original = original[key]
        for key in original:
            contract = deepcopy(base)
            target = contract
            for step in path:
                target = target[step]
            del target[key]
            assert analyze_clips(contract)["code"] == "invalid_contract"
        contract = deepcopy(base)
        target = contract
        for step in path:
            target = target[step]
        target["private"] = "PRIVATE_SENTINEL"
        assert analyze_clips(contract)["code"] == "invalid_contract"
