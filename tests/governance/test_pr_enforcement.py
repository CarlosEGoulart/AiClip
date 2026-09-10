"""Integration tests for PR governance enforcement.

Tests the orchestration script that integrates all five validators
against deterministic fixtures representing GitHub event payloads.
"""

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

FIXTURES_DIR = Path(__file__).parent / "fixtures"


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

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_valid_pr_context_passes(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/09/fix/complete-pr-governance-enforcement"
        mock_commits.return_value = ["fix(governance): complete PR enforcement"]
        event_file = self._make_event_file("valid-pr-event.json")
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                from pr_enforcement import run_checks
                errors = run_checks()
                # Filter out evidence file warning (expected when evidence doesn't exist yet)
                real_errors = [e for e in errors if "evidence.md" not in e]
                self.assertEqual(real_errors, [])
        finally:
            os.unlink(event_file)

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
