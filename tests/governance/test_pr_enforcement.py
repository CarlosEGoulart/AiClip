"""Integration tests for PR governance enforcement.

Tests the orchestration script that integrates all five validators
against deterministic fixtures representing GitHub event payloads.
"""

import json
import os
import shutil
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

FIXTURES_DIR = Path(__file__).parent / "fixtures"
REPO_ROOT = Path(__file__).resolve().parents[2]

VALID_EVIDENCE = """# Evidence

## TDD Evidence

### RED

Expected behavior failed before implementation.

### GREEN

Implementation satisfies the behavior.

### REFACTOR

Tests remain green.

## Independent Tester Review

Reviewer: Tester

Decision: APPROVE
"""

REJECT_EVIDENCE = """# Evidence

## Independent Tester Review

Reviewer: Tester

Decision: REJECT
"""

BARE_NA_EVIDENCE = """# Evidence

## TDD Evidence

TDD: N/A

## Independent Tester Review

Reviewer: Tester

Decision: APPROVE
"""


class TestPrEnforcementIntegration(unittest.TestCase):
    """Test the PR enforcement orchestration."""

    def _load_fixture(self, name: str) -> dict:
        with open(FIXTURES_DIR / name) as f:
            return json.load(f)

    def _make_event_file(self, fixture_name: str) -> str:
        """Create a temp file with event payload, return path."""
        data = self._load_fixture(fixture_name)
        f = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(data, f)
        f.close()
        return f.name

    def _make_event_file_with_body(self, body: str) -> str:
        """Create a temp event file with custom PR body."""
        data = self._load_fixture("valid-pr-event.json")
        data["pull_request"]["body"] = body
        f = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(data, f)
        f.close()
        return f.name

    def _create_temp_specs(self, issue_number: int, evidence_content: str) -> Path:
        """Create temporary specs directory with evidence file."""
        specs_dir = REPO_ROOT / "specs"
        backup_dir = None
        if specs_dir.exists():
            backup_dir = REPO_ROOT / f".specs_backup_{os.getpid()}"
            shutil.move(str(specs_dir), str(backup_dir))
        new_specs = specs_dir / f"{issue_number:03d}-test-issue"
        new_specs.mkdir(parents=True)
        (new_specs / "evidence.md").write_text(evidence_content)
        return specs_dir

    def _restore_specs(self, backup_dir: Path | None):
        """Restore original specs directory."""
        specs_dir = REPO_ROOT / "specs"
        if backup_dir and backup_dir.exists():
            if specs_dir.exists():
                shutil.rmtree(str(specs_dir))
            shutil.move(str(backup_dir), str(specs_dir))

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_valid_pr_context_passes(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/99/fix/test-issue"
        mock_commits.return_value = ["fix(governance): test enforcement"]
        event_file = self._make_event_file_with_body(
            "## Summary\n\nFix.\n\n## Scope\n\n- governance\n\n"
            "## TDD Evidence\n\n### RED\n\nFail.\n\n### GREEN\n\nPass.\n\n"
            "### REFACTOR\n\nClean.\n\n## Tests\n\n- [x] Pass\n\nCloses #99"
        )
        backup = None
        try:
            specs_dir = self._create_temp_specs(99, VALID_EVIDENCE)
            backup = REPO_ROOT / f".specs_backup_{os.getpid()}"
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertEqual(errors, [])
        finally:
            os.unlink(event_file)
            if backup and backup.exists():
                if specs_dir.exists():
                    shutil.rmtree(str(specs_dir))
                shutil.move(str(backup), str(specs_dir))

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_reject_fails_merge_gate(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/99/fix/test-issue"
        mock_commits.return_value = ["fix(governance): test enforcement"]
        event_file = self._make_event_file_with_body(
            "## Summary\n\nFix.\n\n## Scope\n\n- governance\n\n"
            "## TDD Evidence\n\n### RED\n\nFail.\n\n### GREEN\n\nPass.\n\n"
            "### REFACTOR\n\nClean.\n\n## Tests\n\n- [x] Pass\n\nCloses #99"
        )
        backup = None
        try:
            specs_dir = self._create_temp_specs(99, REJECT_EVIDENCE)
            backup = REPO_ROOT / f".specs_backup_{os.getpid()}"
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("REJECT" in e for e in errors))
        finally:
            os.unlink(event_file)
            if backup and backup.exists():
                if specs_dir.exists():
                    shutil.rmtree(str(specs_dir))
                shutil.move(str(backup), str(specs_dir))

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_bare_na_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/99/fix/test-issue"
        mock_commits.return_value = ["fix(governance): test enforcement"]
        event_file = self._make_event_file_with_body(
            "## Summary\n\nFix.\n\n## Scope\n\n- governance\n\n"
            "## TDD Evidence\n\n### RED\n\nFail.\n\n### GREEN\n\nPass.\n\n"
            "### REFACTOR\n\nClean.\n\n## Tests\n\n- [x] Pass\n\nCloses #99"
        )
        backup = None
        try:
            specs_dir = self._create_temp_specs(99, BARE_NA_EVIDENCE)
            backup = REPO_ROOT / f".specs_backup_{os.getpid()}"
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("TDD" in e for e in errors))
        finally:
            os.unlink(event_file)
            if backup and backup.exists():
                if specs_dir.exists():
                    shutil.rmtree(str(specs_dir))
                shutil.move(str(backup), str(specs_dir))

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_invalid_commit_message_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/09/fix/complete-pr-governance-enforcement"
        mock_commits.return_value = ["fix governance enforcement"]
        event_file = self._make_event_file("valid-pr-event.json")
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("Invalid commit format" in e for e in errors))
        finally:
            os.unlink(event_file)

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_invalid_branch_name_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "feat/branch-without-prefix"
        mock_commits.return_value = ["fix(governance): complete PR enforcement"]
        event_file = self._make_event_file("valid-pr-event.json")
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("Invalid branch name" in e for e in errors))
        finally:
            os.unlink(event_file)

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_missing_closes_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/09/fix/complete-pr-governance-enforcement"
        mock_commits.return_value = ["fix(governance): complete PR enforcement"]
        event = self._load_fixture("valid-pr-event.json")
        event["pull_request"]["body"] = "## Summary\n\nFix.\n\n## Scope\n\n- governance\n\n## TDD Evidence\n\nN/A.\n\n## Tests\n\n- [x] Pass"
        f = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(event, f)
        f.close()
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": f.name}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("Closes" in e for e in errors))
        finally:
            os.unlink(f.name)

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_branch_issue_mismatch_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/9/fix/complete-pr-governance-enforcement"
        mock_commits.return_value = ["fix(governance): complete PR enforcement"]
        event_file = self._make_event_file("wrong-issue-pr-event.json")
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("does not match" in e for e in errors))
        finally:
            os.unlink(event_file)

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_multiple_closes_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/9/fix/complete-pr-governance-enforcement"
        mock_commits.return_value = ["fix(governance): complete PR enforcement"]
        event = self._load_fixture("valid-pr-event.json")
        event["pull_request"]["body"] += "\nCloses #11"
        f = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(event, f)
        f.close()
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": f.name}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("exactly one issue" in e for e in errors))
        finally:
            os.unlink(f.name)

    def test_missing_event_path_fails(self):
        with patch.dict(os.environ, {"GITHUB_EVENT_PATH": ""}, clear=False):
            from pr_enforcement import run_checks
            errors = run_checks()
            self.assertTrue(any("GITHUB_EVENT_PATH" in e for e in errors))


if __name__ == "__main__":
    unittest.main()
