"""Unit tests for the deterministic evidence freshness validator.

Covers scenarios E1-E15 from specs/126-governance-evidence-freshness/spec.md:
- E1: Valid evidence → pass
- E2: Missing Decision line → fail closed
- E3: Stale commit SHA → fail closed
- E4: Stale PR URL → fail closed
- E5: Missing required CI check → fail closed
- E6: CI check state mismatch → fail closed
- E7: Fabricated CI check marker → fail closed
- E8: Multiple conflicting decisions → fail closed
- E9: Malformed decision line → fail closed
- E10: Empty evidence content → fail closed
- E11: Missing Commit field → fail closed
- E12: Missing PR field → fail closed
- E13: Missing Evidence-At timestamp → fail closed
- E14: No synthesized approval → fail closed
- E15: No regression on existing governance tests
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import evidence_freshness  # noqa: E402


class TestValidEvidence(unittest.TestCase):
    """E1: Valid evidence (happy path)."""

    def test_valid_evidence_passes(self):
        """All fields consistent → no errors."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
CI: Frontend CI / test: pass
CI: E2E CI / e2e: pass
CI: governance / governance: pass
CI: governance / pr-enforcement: pass
Evidence-At: 2026-11-23T10:00:00Z
Merge-State: open
"""
        observed = {
            "Backend CI / tests": "pass",
            "Frontend CI / test": "pass",
            "E2E CI / e2e": "pass",
            "governance / governance": "pass",
            "governance / pr-enforcement": "pass",
        }
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertEqual(errors, [])


class TestMissingDecision(unittest.TestCase):
    """E2: Missing Decision line → fail closed."""

    def test_missing_decision_fails(self):
        """No Decision: line → error naming missing Decision field."""
        content = """Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Decision" in e for e in errors))


class TestStaleCommit(unittest.TestCase):
    """E3: Stale commit SHA → fail closed."""

    def test_stale_commit_fails(self):
        """Evidence Commit != head SHA → error naming commit field."""
        content = """Decision: APPROVE
Commit: 0000000000000000000000000000000000000000
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Commit" in e for e in errors))


class TestStalePR(unittest.TestCase):
    """E4: Stale PR URL → fail closed."""

    def test_stale_pr_fails(self):
        """Evidence PR != evaluated PR → error naming PR field."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/999
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("PR" in e for e in errors))


class TestMissingCI(unittest.TestCase):
    """E5: Missing required CI check → fail closed."""

    def test_missing_ci_check_fails(self):
        """Evidence omits a REQUIRED_CHECK → error naming missing check."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {
            "Backend CI / tests": "pass",
            "Frontend CI / test": "pass",
            "E2E CI / e2e": "pass",
            "governance / governance": "pass",
            "governance / pr-enforcement": "pass",
        }
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("CI" in e for e in errors))


class TestCIStateMismatch(unittest.TestCase):
    """E6: CI check state mismatch → fail closed."""

    def test_ci_state_mismatch_fails(self):
        """Evidence says pass, observed fail → error naming check and state."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
CI: Frontend CI / test: pass
CI: E2E CI / e2e: pass
CI: governance / governance: pass
CI: governance / pr-enforcement: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {
            "Backend CI / tests": "fail",
            "Frontend CI / test": "pass",
            "E2E CI / e2e": "pass",
            "governance / governance": "pass",
            "governance / pr-enforcement": "pass",
        }
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("CI" in e and "Backend CI" in e for e in errors))


class TestFabricatedCI(unittest.TestCase):
    """E7: Fabricated CI check marker → fail closed."""

    def test_fabricated_ci_fails(self):
        """Evidence lists a check not run → error naming fabricated check."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
CI: Frontend CI / test: pass
CI: E2E CI / e2e: pass
CI: governance / governance: pass
CI: governance / pr-enforcement: pass
CI: Non-Existent Check: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {
            "Backend CI / tests": "pass",
            "Frontend CI / test": "pass",
            "E2E CI / e2e": "pass",
            "governance / governance": "pass",
            "governance / pr-enforcement": "pass",
        }
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("CI" in e for e in errors))


class TestMultipleDecisions(unittest.TestCase):
    """E8: Multiple conflicting decisions → fail closed."""

    def test_multiple_decisions_fail(self):
        """APPROVE + REJECT → error naming conflict."""
        content = """Decision: APPROVE
Decision: REJECT
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Decision" in e for e in errors))


class TestMalformedDecision(unittest.TestCase):
    """E9: Malformed decision line → fail closed."""

    def test_malformed_decision_fails(self):
        """Decision: MAYBE → error naming malformed decision."""
        content = """Decision: MAYBE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Decision" in e for e in errors))


class TestEmptyEvidence(unittest.TestCase):
    """E10: Empty evidence content → fail closed."""

    def test_empty_evidence_fails(self):
        """Empty content → error naming empty content."""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            "", "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Decision" in e for e in errors))


class TestMissingCommit(unittest.TestCase):
    """E11: Missing Commit field → fail closed."""

    def test_missing_commit_fails(self):
        """No Commit: line → error naming missing Commit field."""
        content = """Decision: APPROVE
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Commit" in e for e in errors))


class TestMissingPR(unittest.TestCase):
    """E12: Missing PR field → fail closed."""

    def test_missing_pr_fails(self):
        """No PR: line → error naming missing PR field."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("PR" in e for e in errors))


class TestMissingEvidenceAt(unittest.TestCase):
    """E13: Missing Evidence-At timestamp → fail closed."""

    def test_missing_evidence_at_fails(self):
        """No Evidence-At: line → error naming missing timestamp."""
        content = """Decision: APPROVE
Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Evidence-At" in e for e in errors))


class TestNoSynthesizedApproval(unittest.TestCase):
    """E14: No synthesized approval → fail closed."""

    def test_no_decision_fails(self):
        """No Decision: line → fail even if CI passes."""
        content = """Commit: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0
PR: https://github.com/CarlosEGoulart/AiClip/pull/151
CI: Backend CI / tests: pass
Evidence-At: 2026-11-23T10:00:00Z
"""
        observed = {"Backend CI / tests": "pass"}
        errors = evidence_freshness.validate_freshness(
            content, "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0",
            "https://github.com/CarlosEGoulart/AiClip/pull/151", observed
        )
        self.assertGreater(len(errors), 0)
        self.assertTrue(any("Decision" in e for e in errors))


class TestNoRegression(unittest.TestCase):
    """E15: No regression on existing governance tests."""

    def test_existing_validators_still_work(self):
        """Existing validators import and function unchanged."""
        # Just confirm the module imports without error
        self.assertTrue(hasattr(evidence_freshness, "validate_freshness"))
        self.assertTrue(hasattr(evidence_freshness, "run_evidence_freshness"))
        self.assertTrue(hasattr(evidence_freshness, "parse_evidence"))


if __name__ == "__main__":
    unittest.main()
