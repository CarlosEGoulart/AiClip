"""Tests for the worker CLI entry point."""

from __future__ import annotations

import json
import tempfile
from pathlib import Path
from typing import Any
from unittest.mock import patch

import pytest

from aiclip_worker.cli import main

FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"


class TestCLIValidContract:
    """Test CLI with valid contracts."""

    def test_cli_valid_contract_via_file(self, sample_contract: dict[str, Any]) -> None:
        """CLI with --contract-file returns exit code 0 on success."""
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
            json.dump(sample_contract, f)
            f.flush()
            tmp_path = f.name

        try:
            exit_code = main(["probe", "--contract-file", tmp_path])
            assert exit_code == 0
        finally:
            Path(tmp_path).unlink(missing_ok=True)

    def test_cli_valid_contract_via_stdin(self, sample_contract: dict[str, Any], capsys: pytest.CaptureFixture) -> None:
        """CLI with --contract-json returns valid JSON output."""
        contract_json = json.dumps(sample_contract)
        exit_code = main(["probe", "--contract-json", contract_json])
        assert exit_code == 0

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "success"


class TestCLIInvalidContract:
    """Test CLI with invalid contracts."""

    def test_cli_invalid_contract_missing_fields(self) -> None:
        """CLI with missing required fields returns exit code 2."""
        invalid_contract = json.dumps({"version": "1.0.0"})
        exit_code = main(["probe", "--contract-json", invalid_contract])
        assert exit_code == 2

    def test_cli_unknown_major_version(self, sample_contract_unknown_version: dict[str, Any]) -> None:
        """CLI with unknown major version returns exit code 2."""
        contract_json = json.dumps(sample_contract_unknown_version)
        exit_code = main(["probe", "--contract-json", contract_json])
        assert exit_code == 2

    def test_cli_no_contract_provided(self) -> None:
        """CLI with no contract provided returns exit code 2."""
        # When stdin is a TTY and no contract-json/file is given
        with patch("sys.stdin.isatty", return_value=True):
            exit_code = main(["probe"])
        assert exit_code == 2


class TestCLIExitCodes:
    """Test CLI exit code behavior."""

    def test_cli_exit_code_success(self, sample_contract: dict[str, Any]) -> None:
        """Exit code 0 on successful probe."""
        contract_json = json.dumps(sample_contract)
        exit_code = main(["probe", "--contract-json", contract_json])
        assert exit_code == 0

    def test_cli_exit_code_processing_error(self, sample_contract_corrupt: dict[str, Any]) -> None:
        """Exit code 1 on processing error (corrupt file)."""
        contract_json = json.dumps(sample_contract_corrupt)
        exit_code = main(["probe", "--contract-json", contract_json])
        assert exit_code == 1

    def test_cli_exit_code_invalid_input(self) -> None:
        """Exit code 2 on invalid input."""
        exit_code = main(["probe", "--contract-json", "not valid json"])
        assert exit_code == 2

    def test_cli_exit_code_nonexistent_storage_key(self, sample_contract_nonexistent: dict[str, Any]) -> None:
        """Exit code 1 on non-existent storage key."""
        contract_json = json.dumps(sample_contract_nonexistent)
        exit_code = main(["probe", "--contract-json", contract_json])
        assert exit_code == 1


class TestCLIOutput:
    """Test CLI output format."""

    def test_cli_success_output_is_valid_json(self, sample_contract: dict[str, Any], capsys: pytest.CaptureFixture) -> None:
        """Successful output is valid JSON with expected structure."""
        contract_json = json.dumps(sample_contract)
        main(["probe", "--contract-json", contract_json])

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert "status" in output
        assert "probe" in output

    def test_cli_error_output_is_valid_json(self, sample_contract_corrupt: dict[str, Any], capsys: pytest.CaptureFixture) -> None:
        """Error output is valid JSON with expected structure."""
        contract_json = json.dumps(sample_contract_corrupt)
        main(["probe", "--contract-json", contract_json])

        captured = capsys.readouterr()
        output = json.loads(captured.out)
        assert output["status"] == "error"
        assert "error" in output
