"""Governance enforcement tests for issue #7.

Tests five validator functions: commit messages, branch names, PR bodies,
evidence decisions, and TDD sections. Covers valid, invalid, and edge cases.
"""

import unittest

from validators import (
    validate_branch_name,
    validate_commit_message,
    validate_evidence_decision,
    validate_pr_body,
    validate_tdd_sections,
)


class TestCommitMessageValidator(unittest.TestCase):
    def test_valid_feat_with_scope(self):
        self.assertEqual(
            validate_commit_message("feat(clips): generate ranked clip candidates"),
            [],
        )

    def test_valid_docs_with_scope(self):
        self.assertEqual(
            validate_commit_message("docs(architecture): resolve MVP inconsistencies"),
            [],
        )

    def test_valid_ci_with_scope(self):
        self.assertEqual(
            validate_commit_message("ci(playwright): upload traces on test failure"),
            [],
        )

    def test_valid_fix_with_scope(self):
        self.assertEqual(
            validate_commit_message("fix(auth): preserve csrf session"),
            [],
        )

    def test_valid_chore_with_scope(self):
        self.assertEqual(
            validate_commit_message("chore(deps): update pyyaml"),
            [],
        )

    def test_valid_refactor_with_scope(self):
        self.assertEqual(
            validate_commit_message("refactor(media): isolate ffmpeg command builder"),
            [],
        )

    def test_valid_test_with_scope(self):
        self.assertEqual(
            validate_commit_message("test(api): add health endpoint coverage"),
            [],
        )

    def test_valid_perf_with_scope(self):
        self.assertEqual(
            validate_commit_message("perf(clips): optimize ranking algorithm"),
            [],
        )

    def test_invalid_no_scope(self):
        errors = validate_commit_message("docs: resolve MVP inconsistencies")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid commit format", errors[0])

    def test_invalid_empty_scope(self):
        errors = validate_commit_message("feat(): empty scope")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid commit format", errors[0])

    def test_invalid_uppercase_scope(self):
        errors = validate_commit_message("feat(Scope): uppercase scope")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid commit format", errors[0])

    def test_invalid_type(self):
        errors = validate_commit_message("random(text): invalid type")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid commit format", errors[0])

    def test_invalid_empty_subject(self):
        errors = validate_commit_message("feat(clips): ")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid commit format", errors[0])

    def test_invalid_empty_string(self):
        errors = validate_commit_message("")
        self.assertEqual(len(errors), 1)
        self.assertIn("must not be empty", errors[0])

    def test_invalid_whitespace_only(self):
        errors = validate_commit_message("   ")
        self.assertEqual(len(errors), 1)
        self.assertIn("must not be empty", errors[0])


class TestBranchNameValidator(unittest.TestCase):
    def test_valid_chore_branch(self):
        self.assertEqual(
            validate_branch_name("@carlosegoulart/07/chore/governance-enforcement"),
            [],
        )

    def test_valid_feat_branch(self):
        self.assertEqual(
            validate_branch_name("@carlosegoulart/15/feat/audio-chunk-manager"),
            [],
        )

    def test_valid_docs_branch(self):
        self.assertEqual(
            validate_branch_name("@carlosegoulart/3/docs/define-mvp-architecture"),
            [],
        )

    def test_valid_fix_branch(self):
        self.assertEqual(
            validate_branch_name("@carlosegoulart/10/fix/caption-timing"),
            [],
        )

    def test_valid_refactor_branch(self):
        self.assertEqual(
            validate_branch_name("@carlosegoulart/5/refactor/extract-ffmpeg"),
            [],
        )

    def test_valid_test_branch(self):
        self.assertEqual(
            validate_branch_name("@carlosegoulart/8/test/add-clip-tests"),
            [],
        )

    def test_invalid_no_prefix(self):
        errors = validate_branch_name("feat/branch-without-prefix")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid branch name", errors[0])

    def test_invalid_non_numeric_issue(self):
        errors = validate_branch_name("@carlosegoulart/abc/chore/desc")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid branch name", errors[0])

    def test_invalid_bad_type(self):
        errors = validate_branch_name("@carlosegoulart/7/random/desc")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid branch name", errors[0])

    def test_invalid_uppercase_description(self):
        errors = validate_branch_name("@carlosegoulart/7/chore/Description-With-Uppercase")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid branch name", errors[0])

    def test_invalid_underscore_in_description(self):
        errors = validate_branch_name("@carlosegoulart/7/chore/has_underscore")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid branch name", errors[0])

    def test_invalid_space_in_description(self):
        errors = validate_branch_name("@carlosegoulart/7/chore/has space")
        self.assertEqual(len(errors), 1)
        self.assertIn("Invalid branch name", errors[0])

    def test_invalid_empty_string(self):
        errors = validate_branch_name("")
        self.assertEqual(len(errors), 1)
        self.assertIn("must not be empty", errors[0])


class TestPrBodyValidator(unittest.TestCase):
    def test_valid_pr_body(self):
        body = """## Summary

Fix authentication flow.

## Scope

- auth module

## TDD Evidence

### RED
Tests written.

### GREEN
Implementation done.

### REFACTOR
Cleaned up.

## Tests

- [x] Unit tests

Closes #7"""
        self.assertEqual(validate_pr_body(body), [])

    def test_valid_lowercase_closes(self):
        body = """## Summary
Fix.

## Scope
Auth.

## TDD Evidence
N/A.

## Tests
- [x] Pass.

closes #7"""
        self.assertEqual(validate_pr_body(body), [])

    def test_invalid_no_closes(self):
        body = """## Summary
Fix.

## Scope
Auth.

## TDD Evidence
N/A.

## Tests
- [x] Pass."""
        errors = validate_pr_body(body)
        self.assertTrue(any("Closes" in e for e in errors))

    def test_invalid_missing_tdd_section(self):
        body = """## Summary
Fix.

## Scope
Auth.

## Tests
- [x] Pass.

Closes #7"""
        errors = validate_pr_body(body)
        self.assertTrue(any("TDD Evidence" in e for e in errors))

    def test_invalid_empty_body(self):
        errors = validate_pr_body("")
        self.assertEqual(len(errors), 1)
        self.assertIn("must not be empty", errors[0])


class TestEvidenceDecisionValidator(unittest.TestCase):
    def test_valid_approve(self):
        content = "## Review\n\nDecision: APPROVE\n\nAll checks pass."
        self.assertEqual(validate_evidence_decision(content), [])

    def test_valid_reject(self):
        content = "## Review\n\nDecision: REJECT\n\nDefects found."
        self.assertEqual(validate_evidence_decision(content), [])

    def test_invalid_both_approve_and_reject(self):
        content = "Decision: APPROVE and Decision: REJECT"
        errors = validate_evidence_decision(content)
        self.assertEqual(len(errors), 1)
        self.assertIn("exactly one", errors[0])

    def test_invalid_no_decision(self):
        content = "## Review\n\nAll checks pass."
        errors = validate_evidence_decision(content)
        self.assertEqual(len(errors), 1)
        self.assertIn("must contain", errors[0])

    def test_invalid_empty_content(self):
        errors = validate_evidence_decision("")
        self.assertEqual(len(errors), 1)
        self.assertIn("must not be empty", errors[0])


class TestTddSectionsValidator(unittest.TestCase):
    def test_valid_all_headings(self):
        content = "### RED\nFailing test.\n### GREEN\nPassing.\n### REFACTOR\nClean."
        self.assertEqual(validate_tdd_sections(content), [])

    def test_valid_na_with_reason(self):
        content = "TDD: N/A — governance-only change with no executable behavior."
        self.assertEqual(validate_tdd_sections(content), [])

    def test_valid_na_lowercase(self):
        content = "tdd: n/a — documentation only."
        self.assertEqual(validate_tdd_sections(content), [])

    def test_invalid_no_headings_no_na(self):
        content = "## Review\n\nAll checks pass."
        errors = validate_tdd_sections(content)
        self.assertEqual(len(errors), 1)
        self.assertIn("must contain", errors[0])

    def test_invalid_only_red(self):
        content = "### RED\nFailing test."
        errors = validate_tdd_sections(content)
        self.assertEqual(len(errors), 1)
        self.assertIn("must contain", errors[0])

    def test_invalid_empty_content(self):
        errors = validate_tdd_sections("")
        self.assertEqual(len(errors), 1)
        self.assertIn("must not be empty", errors[0])


if __name__ == "__main__":
    unittest.main()
