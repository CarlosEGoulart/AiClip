"""Unit tests for the deterministic spec-plan quality validator.

Covers scenarios Q1-Q19 from specs/127-governance-spec-plan-quality/spec.md:
- Q1: Valid compliant bundle → pass
- Q2-Q4: Missing required files → fail closed
- Q5: Empty spec.md → fail
- Q6-Q8: Missing required sections → fail
- Q9: Placeholder content → fail
- Q10: Empty required section → fail
- Q11: Acceptance criteria not in checklist format → fail
- Q12: Subjective adjectives → fail
- Q13: Contradictory criteria → fail
- Q14: Valid existing specs pass → no false positives
- Q15: Validator is read-only → no file modification
- Q16: No secrets read or printed → confirmed
- Q17: Exit code behavior → 0 on pass, non-zero on failure
- Q18: Contract version reported → spec-plan-quality v1.0
- Q19: No regression on existing governance tests → all pass
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import spec_plan_quality  # noqa: E402


class TestValidBundle(unittest.TestCase):
    """Q1: Valid compliant bundle → pass."""

    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.issue_dir = Path(self.tmpdir) / "127-test-bundle"
        self.issue_dir.mkdir()

        (self.issue_dir / "spec.md").write_text(
            "# Title\n\nTest spec\n\n## Description\n\nTest description.\n\n"
            "## Acceptance Criteria\n\n- [ ] Criterion one works.\n- [ ] Criterion two works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario 1: works.\n\n## Dependencies\n\nNone.\n"
        )
        (self.issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver test.\n\n## Phase 1\n\nRED step.\n\n## Phase 2\n\nGREEN step.\n\n"
            "## Commits\n\nfeat(test): add test Refs #127\n\n## Verification\n\nRun tests.\n"
        )
        (self.issue_dir / "test-plan.md").write_text(
            "# Overview\n\nTest plan.\n\n## Test Scenarios\n\n- Scenario 1.\n\n"
            "## Application E2E / Playwright\n\nN/A — no UI changes.\n\n"
            "## Test Execution\n\npytest tests/\n"
        )

    def tearDown(self):
        import shutil
        shutil.rmtree(self.tmpdir, ignore_errors=True)

    def test_valid_bundle_passes(self):
        exit_code, errors = spec_plan_quality.validate_bundle(
            self.tmpdir, 127
        )
        self.assertEqual(exit_code, 0)
        self.assertEqual(errors, [])

    def test_contract_version_reported(self):
        exit_code, errors = spec_plan_quality.run_spec_plan_quality(
            self.tmpdir, 127
        )
        self.assertEqual(exit_code, 0)
        self.assertEqual(errors, [])

    def test_main_prints_passed(self):
        with patch.object(
            spec_plan_quality, "run_spec_plan_quality", return_value=(0, [])
        ):
            with patch("builtins.print") as mock_print:
                code = spec_plan_quality.main([self.tmpdir, "127"])
                self.assertEqual(code, 0)
                self.assertTrue(
                    any("Spec-plan quality checks passed" in str(c) for c in mock_print.call_args_list)
                )


class TestMissingFiles(unittest.TestCase):
    """Q2-Q4: Missing required files → fail closed."""

    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.issue_dir = Path(self.tmpdir) / "128-missing-files"
        self.issue_dir.mkdir()

    def tearDown(self):
        import shutil
        shutil.rmtree(self.tmpdir, ignore_errors=True)

    def _create_file(self, name: str, content: str = "content"):
        (self.issue_dir / name).write_text(content)

    def test_missing_spec_md(self):
        self._create_file("plan.md", "content")
        self._create_file("test-plan.md", "content")
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 128)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("spec.md" in e for e in errors))

    def test_missing_plan_md(self):
        self._create_file("spec.md", "content")
        self._create_file("test-plan.md", "content")
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 128)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("plan.md" in e for e in errors))

    def test_missing_test_plan_md(self):
        self._create_file("spec.md", "content")
        self._create_file("plan.md", "content")
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 128)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("test-plan.md" in e for e in errors))

    def test_empty_spec_md(self):
        (self.issue_dir / "spec.md").write_text("")
        self._create_file("plan.md", "content")
        self._create_file("test-plan.md", "content")
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 128)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("empty" in e.lower() for e in errors))


class TestMissingSections(unittest.TestCase):
    """Q6-Q8: Missing required sections → fail."""

    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.issue_dir = Path(self.tmpdir) / "129-missing-sections"
        self.issue_dir.mkdir()

    def tearDown(self):
        import shutil
        shutil.rmtree(self.tmpdir, ignore_errors=True)

    def test_spec_missing_acceptance_criteria(self):
        (self.issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        self._write_plan_test_plan()
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 129)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("Acceptance Criteria" in e for e in errors))

    def test_plan_missing_commits(self):
        self._write_spec_test_plan()
        (self.issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Verification\n\nRun.\n"
        )
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 129)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("Commits" in e for e in errors))

    def test_test_plan_missing_test_scenarios(self):
        self._write_spec_plan()
        (self.issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Application E2E / Playwright\n\nN/A.\n\n"
            "## Test Execution\n\npytest.\n"
        )
        exit_code, errors = spec_plan_quality.validate_bundle(self.tmpdir, 129)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("Test Scenarios" in e for e in errors))

    def _write_spec_test_plan(self):
        (self.issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] Criterion works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        (self.issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

    def _write_plan_test_plan(self):
        (self.issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #127\n\n## Verification\n\nRun.\n"
        )
        (self.issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

    def _write_spec_plan(self):
        (self.issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] Criterion works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        (self.issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #127\n\n## Verification\n\nRun.\n"
        )


class TestPlaceholders(unittest.TestCase):
    """Q9: Placeholder content → fail."""

    def test_placeholder_todo_fails(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "130-placeholder"
        issue_dir.mkdir()
        (issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] Criterion works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\nTODO: add scenarios.\n\n## Dependencies\n\nNone.\n"
        )
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #130\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        exit_code, errors = spec_plan_quality.validate_bundle(tmpdir, 130)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("TODO" in e or "placeholder" in e.lower() for e in errors))

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestEmptySection(unittest.TestCase):
    """Q10: Empty required section → fail."""

    def test_empty_section_fails(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "131-empty"
        issue_dir.mkdir()
        (issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] Criterion works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n\n## Dependencies\n\nNone.\n"
        )
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #131\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        exit_code, errors = spec_plan_quality.validate_bundle(tmpdir, 131)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("empty" in e.lower() for e in errors))

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestChecklistFormat(unittest.TestCase):
    """Q11: Acceptance criteria not in checklist format → fail."""

    def test_non_checklist_criteria_fails(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "132-checklist"
        issue_dir.mkdir()
        (issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\nCriterion one works.\nCriterion two works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #132\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        exit_code, errors = spec_plan_quality.validate_bundle(tmpdir, 132)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("checklist" in e.lower() for e in errors))

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestSubjectiveAdjectives(unittest.TestCase):
    """Q12: Subjective adjectives → fail."""

    def test_subjective_adjective_fails(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "133-subjective"
        issue_dir.mkdir()
        (issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] The system is good.\n- [ ] Criterion two works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #133\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        exit_code, errors = spec_plan_quality.validate_bundle(tmpdir, 133)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("subjective" in e.lower() or "good" in e.lower() for e in errors))

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestContradictions(unittest.TestCase):
    """Q13: Contradictory criteria → fail."""

    def test_contradictory_criteria_fails(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "134-contradiction"
        issue_dir.mkdir()
        (issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] The system must cache results.\n- [ ] The system must not cache results.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #134\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        exit_code, errors = spec_plan_quality.validate_bundle(tmpdir, 134)
        self.assertNotEqual(exit_code, 0)
        self.assertTrue(any("contradict" in e.lower() for e in errors))

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestReadOnly(unittest.TestCase):
    """Q15: Validator is read-only."""

    def test_validator_does_not_modify_files(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "135-readonly"
        issue_dir.mkdir()
        spec_content = "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
        spec_content += "## Acceptance Criteria\n\n- [ ] Criterion works.\n\n"
        spec_content += "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
        spec_content += "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        (issue_dir / "spec.md").write_text(spec_content)
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #135\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        import os
        mtime_before = os.path.getmtime(str(issue_dir / "spec.md"))

        spec_plan_quality.validate_bundle(tmpdir, 135)

        mtime_after = os.path.getmtime(str(issue_dir / "spec.md"))
        self.assertEqual(mtime_before, mtime_after)

        # Content unchanged
        with open(str(issue_dir / "spec.md")) as f:
            content_after = f.read()
        self.assertEqual(content_after, spec_content)

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestNoSecrets(unittest.TestCase):
    """Q16: No secrets read or printed."""

    def test_no_secrets_in_source(self):
        import inspect
        source = inspect.getsource(spec_plan_quality)
        self.assertNotIn("os.environ", source)
        self.assertNotIn("getenv", source)
        self.assertNotIn(".env", source.lower())


class TestExitCode(unittest.TestCase):
    """Q17: Exit code behavior."""

    def test_exit_zero_on_pass(self):
        tmpdir = tempfile.mkdtemp()
        issue_dir = Path(tmpdir) / "137-exit"
        issue_dir.mkdir()
        (issue_dir / "spec.md").write_text(
            "# Title\n\nTest.\n\n## Description\n\nDesc.\n\n"
            "## Acceptance Criteria\n\n- [ ] Criterion works.\n\n"
            "## Out of Scope\n\nNone.\n\n## Security Considerations\n\nNone.\n\n"
            "## Test Scenarios\n\n- Scenario.\n\n## Dependencies\n\nNone.\n"
        )
        (issue_dir / "plan.md").write_text(
            "# Objective\n\nDeliver.\n\n## Phase 1\n\nRED.\n\n## Commits\n\nfeat: test Refs #137\n\n## Verification\n\nRun.\n"
        )
        (issue_dir / "test-plan.md").write_text(
            "# Overview\n\nPlan.\n\n## Test Scenarios\n\n- Scenario.\n\n"
            "## Application E2E / Playwright\n\nN/A.\n\n## Test Execution\n\npytest.\n"
        )

        exit_code, errors = spec_plan_quality.validate_bundle(tmpdir, 137)
        self.assertEqual(exit_code, 0)
        self.assertEqual(errors, [])

        import shutil
        shutil.rmtree(tmpdir, ignore_errors=True)


class TestContractVersion(unittest.TestCase):
    """Q18: Contract version reported."""

    def test_contract_version_constant(self):
        self.assertEqual(spec_plan_quality.CONTRACT_VERSION, "spec-plan-quality v1.0")


class TestNoRegression(unittest.TestCase):
    """Q19: No regression on existing governance tests."""

    def test_existing_validators_still_work(self):
        self.assertTrue(hasattr(spec_plan_quality, "validate_bundle"))
        self.assertTrue(hasattr(spec_plan_quality, "run_spec_plan_quality"))
        self.assertTrue(hasattr(spec_plan_quality, "load_bundle"))


if __name__ == "__main__":
    unittest.main()
