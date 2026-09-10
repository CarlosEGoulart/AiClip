"""Integration tests for PR governance enforcement.

Tests the orchestration script that integrates all validators
against deterministic fixtures representing GitHub event payloads.
Uses dependency injection for specs directory isolation.
"""

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

FIXTURES_DIR = Path(__file__).parent / "fixtures"

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


def _create_temp_specs(tmp: Path, issue_number: int, evidence_content: str) -> Path:
    """Create temporary specs directory with evidence file."""
    specs_dir = tmp / "specs"
    specs_dir.mkdir()
    issue_dir = specs_dir / f"{issue_number:03d}-test-issue"
    issue_dir.mkdir()
    (issue_dir / "evidence.md").write_text(evidence_content)
    return specs_dir


class TestPrEnforcementIntegration(unittest.TestCase):
    """Test the PR enforcement orchestration with isolated fixtures."""

    def _load_fixture(self, name: str) -> dict:
        with open(FIXTURES_DIR / name) as f:
            return json.load(f)

    def _make_event_file_with_body(self, body: str) -> str:
        """Create a temp event file with custom PR body."""
        data = self._load_fixture("valid-pr-event.json")
        data["pull_request"]["body"] = body
        f = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(data, f)
        f.close()
        return f.name

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
        try:
            with tempfile.TemporaryDirectory() as tmp:
                specs_dir = _create_temp_specs(Path(tmp), 99, VALID_EVIDENCE)
                with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_file}):
                    from pr_enforcement import find_evidence_file, run_checks
                    evidence = find_evidence_file(99, specs_dir)
                    self.assertIsNotNone(evidence)
                    self.assertEqual(evidence.name, "evidence.md")
        finally:
            os.unlink(event_file)

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
        try:
            with tempfile.TemporaryDirectory() as tmp:
                specs_dir = _create_temp_specs(Path(tmp), 99, REJECT_EVIDENCE)
                from pr_enforcement import find_evidence_file
                evidence = find_evidence_file(99, specs_dir)
                self.assertIsNotNone(evidence)
                from validators import validate_merge_approval
                errors = validate_merge_approval(evidence.read_text())
                self.assertTrue(any("REJECT" in e for e in errors))
        finally:
            os.unlink(event_file)

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
        try:
            with tempfile.TemporaryDirectory() as tmp:
                specs_dir = _create_temp_specs(Path(tmp), 99, BARE_NA_EVIDENCE)
                from pr_enforcement import find_evidence_file
                evidence = find_evidence_file(99, specs_dir)
                self.assertIsNotNone(evidence)
                from validators import validate_tdd_sections
                errors = validate_tdd_sections(evidence.read_text())
                self.assertTrue(any("TDD" in e for e in errors))
        finally:
            os.unlink(event_file)

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_invalid_commit_message_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "@carlosegoulart/09/fix/complete-pr-governance-enforcement"
        mock_commits.return_value = ["fix governance enforcement"]
        event_file = self._load_fixture("valid-pr-event.json")
        event_path = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(event_file, event_path)
        event_path.close()
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_path.name}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("Invalid commit format" in e for e in errors))
        finally:
            os.unlink(event_path.name)

    @patch("pr_enforcement.get_commit_messages")
    @patch("pr_enforcement.get_branch_name")
    @patch.dict(os.environ, {"GITHUB_BASE_REF": "master"})
    def test_invalid_branch_name_fails(self, mock_branch, mock_commits):
        mock_branch.return_value = "feat/branch-without-prefix"
        mock_commits.return_value = ["fix(governance): complete PR enforcement"]
        event_file = self._load_fixture("valid-pr-event.json")
        event_path = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(event_file, event_path)
        event_path.close()
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": event_path.name}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("Invalid branch name" in e for e in errors))
        finally:
            os.unlink(event_path.name)

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
        event = self._load_fixture("wrong-issue-pr-event.json")
        f = tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False)
        json.dump(event, f)
        f.close()
        try:
            with patch.dict(os.environ, {"GITHUB_EVENT_PATH": f.name}):
                from pr_enforcement import run_checks
                errors = run_checks()
                self.assertTrue(any("does not match" in e for e in errors))
        finally:
            os.unlink(f.name)

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

    def test_isolated_specs_not_mutated(self):
        """Temporary test specs do not modify repository specs/."""
        repo_specs = Path(__file__).resolve().parents[2] / "specs"
        original_dirs = sorted(d.name for d in repo_specs.iterdir() if d.is_dir())
        with tempfile.TemporaryDirectory() as tmp:
            specs_dir = _create_temp_specs(Path(tmp), 99, VALID_EVIDENCE)
            from pr_enforcement import find_evidence_file
            result = find_evidence_file(99, specs_dir)
            self.assertIsNotNone(result)
        after_dirs = sorted(d.name for d in repo_specs.iterdir() if d.is_dir())
        self.assertEqual(original_dirs, after_dirs)

    def test_find_evidence_zero_matches_fails(self):
        with tempfile.TemporaryDirectory() as tmp:
            specs_dir = Path(tmp) / "specs"
            specs_dir.mkdir()
            from pr_enforcement import find_evidence_file
            result = find_evidence_file(99, specs_dir)
            self.assertIsNone(result)

    def test_find_evidence_multiple_matches_fails(self):
        with tempfile.TemporaryDirectory() as tmp:
            specs_dir = Path(tmp) / "specs"
            specs_dir.mkdir()
            for i in range(2):
                d = specs_dir / f"099-variant-{i}"
                d.mkdir()
                (d / "evidence.md").write_text("Evidence")
            from pr_enforcement import find_evidence_file
            result = find_evidence_file(99, specs_dir)
            self.assertIsNone(result)


if __name__ == "__main__":
    unittest.main()
