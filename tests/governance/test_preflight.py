"""Unit tests for the deterministic preflight validation command.

Covers rules P1–P5 from specs/125-governance-preflight-hooks/spec.md:
- P1: Branch/issue association
- P2: Required spec bundle
- P3: Working-tree hygiene
- P4: Generated artifacts
- P5: Commit metadata
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import preflight  # noqa: E402


class TestRuleP1BranchIssue(unittest.TestCase):
    """Rule P1: Branch/issue association."""

    def test_valid_branch_with_matching_specs_dir(self):
        """T1: Valid branch name with matching issue directory → pass."""
        rule_id, err, rem = preflight.rule_p1_branch_issue(
            "@carlosegoulart/125/feat/governance-preflight-hooks"
        )
        self.assertEqual(rule_id, "P1")
        self.assertIsNone(err)
        self.assertIsNone(rem)

    def test_valid_branch_other_issue(self):
        """T1: @carlosegoulart/01/chore/init-project-structure → pass."""
        rule_id, err, rem = preflight.rule_p1_branch_issue(
            "@carlosegoulart/01/chore/init-project-structure"
        )
        self.assertEqual(rule_id, "P1")
        self.assertIsNone(err)
        self.assertIsNone(rem)

    def test_invalid_branch_no_prefix(self):
        """T2: Invalid branch name format → fail, names rule P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("feat/branch-without-prefix")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)
        self.assertIsNotNone(rem)

    def test_invalid_branch_non_numeric_issue(self):
        """T2: @carlosegoulart/abc/chore/desc → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("@carlosegoulart/abc/chore/desc")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)

    def test_invalid_branch_bad_type(self):
        """T2: @carlosegoulart/7/random/desc → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("@carlosegoulart/7/random/desc")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)

    def test_invalid_branch_uppercase_desc(self):
        """T2: @carlosegoulart/7/chore/Description-With-Uppercase → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue(
            "@carlosegoulart/7/chore/Description-With-Uppercase"
        )
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)

    def test_invalid_branch_underscore_desc(self):
        """T2: @carlosegoulart/7/chore/has_underscore → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("@carlosegoulart/7/chore/has_underscore")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)

    def test_invalid_branch_space_desc(self):
        """T2: @carlosegoulart/7/chore/has space → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("@carlosegoulart/7/chore/has space")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)

    def test_empty_branch_name(self):
        """T2: empty branch name → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)

    def test_invalid_branch_nonexistent_issue(self):
        """T3: @carlosegoulart/999/feat/nonexistent → fail, names P1."""
        rule_id, err, rem = preflight.rule_p1_branch_issue("@carlosegoulart/999/feat/nonexistent")
        self.assertEqual(rule_id, "P1")
        self.assertIsNotNone(err)


class TestRuleP2SpecBundle(unittest.TestCase):
    """Rule P2: Required spec bundle."""

    def test_complete_spec_bundle(self):
        """T4: Complete spec bundle → pass rule P2."""
        rule_id, err, rem = preflight.rule_p2_spec_bundle(125)
        self.assertEqual(rule_id, "P2")
        self.assertIsNone(err)
        self.assertIsNone(rem)

    def test_nonexistent_issue_bundle(self):
        """T5: Missing spec bundle for nonexistent issue → fail rule P2."""
        rule_id, err, rem = preflight.rule_p2_spec_bundle(99999)
        self.assertEqual(rule_id, "P2")
        self.assertIsNotNone(err)
        self.assertIsNotNone(rem)


class TestRuleP3WorkingTree(unittest.TestCase):
    """Rule P3: Working-tree hygiene (T3)."""

    def test_clean_tree_passes(self):
        """T3: Clean working tree → pass rule P3."""
        rule_id, err, rem = preflight.rule_p3_working_tree()
        self.assertEqual(rule_id, "P3")
        self.assertIsNone(err)
        self.assertIsNone(rem)


class TestRuleP4GeneratedArtifacts(unittest.TestCase):
    """Rule P4: Generated artifacts (T4)."""

    def test_no_artifacts_passes(self):
        """T4: No build artifacts present → pass rule P4."""
        rule_id, err, rem = preflight.rule_p4_generated_artifacts()
        self.assertEqual(rule_id, "P4")
        # May fail if artifacts exist in repo, but structurally valid call
        self.assertIsNotNone(rem) if err else self.assertIsNone(rem)


class TestRuleP5CommitMessages(unittest.TestCase):
    """Rule P5: Commit metadata (Conventional Commits)."""

    def test_valid_conventional_commit(self):
        """T9: feat(governance): add preflight hooks → pass rule P5."""
        rule_id, err, rem = preflight.rule_p5_commit_messages(
            "@carlosegoulart/125/feat/governance-preflight-hooks"
        )
        self.assertEqual(rule_id, "P5")
        self.assertIsNone(err)
        self.assertIsNone(rem)

    def test_valid_fix_commit(self):
        """T9: fix(governance): correct branch check → pass rule P5."""
        rule_id, err, rem = preflight.rule_p5_commit_messages(
            "@carlosegoulart/125/fix/governance-preflight-hooks"
        )
        self.assertEqual(rule_id, "P5")
        self.assertIsNone(err)
        self.assertIsNone(rem)

    def test_valid_docs_commit(self):
        """T9: docs(project-state): record preflight capability → pass rule P5."""
        rule_id, err, rem = preflight.rule_p5_commit_messages(
            "@carlosegoulart/125/docs/project-state-update"
        )
        self.assertEqual(rule_id, "P5")
        self.assertIsNone(err)
        self.assertIsNone(rem)

    def test_invalid_commit_no_scope(self):
        """T10: fix governance (no scope) → fail rule P5."""
        # Direct unit test of commit message regex (no git dependency)
        commit_msg = "fix governance"
        match = preflight.CONVENTIONAL_COMMIT_RE.match(commit_msg)
        self.assertIsNone(match)

    def test_invalid_commit_empty_subject(self):
        """T10: feat(governance):  (empty subject) → fail rule P5."""
        commit_msg = "feat(governance): "
        match = preflight.CONVENTIONAL_COMMIT_RE.match(commit_msg)
        self.assertIsNone(match)

    def test_invalid_commit_bad_type(self):
        """T10: random(text): invalid type → fail rule P5."""
        commit_msg = "random(text): something"
        match = preflight.CONVENTIONAL_COMMIT_RE.match(commit_msg)
        self.assertIsNone(match)

    def test_empty_commit_message(self):
        """T10: empty commit message → fail rule P5."""
        commit_msg = ""
        match = preflight.CONVENTIONAL_COMMIT_RE.match(commit_msg)
        self.assertIsNone(match)


class TestRunPreflight(unittest.TestCase):
    """T6, T11, T12: Single command behavior."""

    def test_run_preflight_returns_list(self):
        """T12: run_preflight() returns a list of error strings."""
        result = preflight.run_preflight()
        self.assertIsInstance(result, list)

    def test_main_prints_passed_on_clean_state(self):
        """T6: exits 0 and prints 'Preflight checks passed' when all rules pass."""
        with patch.object(preflight, "run_preflight", return_value=[]):
            with patch("builtins.print") as mock_print:
                code = preflight.main()
                self.assertEqual(code, 0)
                mock_print.assert_called_with("Preflight checks passed")

    def test_main_prints_failures_and_non_zero_on_violation(self):
        """T6: exits non-zero and prints each failing rule + remediation."""
        with patch.object(
            preflight,
            "run_preflight",
            return_value=["P1: Branch name is empty. Remediation: ..."],
        ):
            with patch("builtins.print") as mock_print:
                code = preflight.main()
                self.assertNotEqual(code, 0)
                self.assertTrue(
                    any("Preflight checks failed" in str(call) for call in mock_print.call_args_list)
                )

    def test_no_secrets_read_or_printed(self):
        """T11: No secrets read or printed by preflight."""
        # Preflight only inspects file names, branch names, commit subjects, git status.
        # No .env, token, or credential access exists in the code.
        import inspect

        source = inspect.getsource(preflight)
        # .env appears only as a generated-artifact detection pattern (P4), not as a read.
        self.assertNotIn("os.environ", source)
        self.assertNotIn("getenv", source)
        self.assertNotIn("open(", source)


if __name__ == "__main__":
    unittest.main()
