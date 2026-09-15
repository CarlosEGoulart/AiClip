"""Regression tests for the deterministic AiClip merge gate."""

from __future__ import annotations

import contextlib
import importlib.util
import io
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


REPO_ROOT = Path(__file__).resolve().parents[2]
MERGE_GATE_PATH = REPO_ROOT / "scripts" / "merge_gate.py"

SPEC = importlib.util.spec_from_file_location(
    "aiclip_merge_gate",
    MERGE_GATE_PATH,
)

if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load scripts/merge_gate.py")

merge_gate = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = merge_gate
SPEC.loader.exec_module(merge_gate)


class TestMergeGate(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)

        issue_dir = self.root / "specs" / "041-hard-merge-gate"
        issue_dir.mkdir(parents=True)

        self.evidence = issue_dir / "evidence.md"
        self.evidence.write_text(
            "Reviewer: Tester\n\nDecision: APPROVE\n",
            encoding="utf-8",
        )

        self.pr = {
            "number": 42,
            "state": "OPEN",
            "isDraft": False,
            "baseRefName": "master",
            "headRefOid": "abc123",
            "mergeable": "MERGEABLE",
            "body": "Closes #41",
            "url": "https://example.invalid/pr/42",
            "mergedAt": None,
        }

        self.issue = {
            "number": 41,
            "state": "OPEN",
            "title": "hard merge gate",
        }

        self.open_issues = [
            {
                "number": 41,
                "title": "hard merge gate",
            }
        ]

        self.checks = []

        for required in merge_gate.REQUIRED_CHECKS:
            workflow, name = required.split(" / ", 1)

            self.checks.append(
                {
                    "workflow": workflow,
                    "name": name,
                    "state": "SUCCESS",
                    "bucket": "pass",
                }
            )

        self.pr_views = []
        self.merge_commands = []

    def tearDown(self):
        self.temp.cleanup()

    def fake_json(self, args, *, allow_nonzero=False):
        if args[:2] == ["pr", "view"]:
            if self.pr_views:
                return self.pr_views.pop(0)
            return dict(self.pr)

        if args[:2] == ["issue", "view"]:
            return dict(self.issue)

        if args[:2] == ["issue", "list"]:
            return [dict(item) for item in self.open_issues]

        if args[:2] == ["pr", "checks"]:
            return [dict(item) for item in self.checks]

        raise AssertionError(f"Unexpected gh JSON call: {args}")

    def fake_text(self, args):
        self.merge_commands.append(list(args))
        return "merged"

    @contextlib.contextmanager
    def patched(self):
        with (
            patch.object(
                merge_gate,
                "gh_json",
                side_effect=self.fake_json,
            ),
            patch.object(
                merge_gate,
                "gh_text",
                side_effect=self.fake_text,
            ),
            patch.object(
                merge_gate,
                "ROOT",
                self.root,
            ),
        ):
            yield

    def set_check(self, canonical_name, *, state, bucket):
        for check in self.checks:
            current = (
                f"{check['workflow']} / {check['name']}"
            )

            if current == canonical_name:
                check["state"] = state
                check["bucket"] = bucket
                return

        raise AssertionError(
            f"Unknown check: {canonical_name}"
        )

    def test_01_rejects_draft_pr(self):
        self.pr["isDraft"] = True

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "PR_DRAFT",
            ):
                merge_gate.validate(42, self.root)

    def test_02_rejects_wrong_base(self):
        self.pr["baseRefName"] = "develop"

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "WRONG_BASE_BRANCH",
            ):
                merge_gate.validate(42, self.root)

    def test_03_rejects_missing_closes_reference(self):
        self.pr["body"] = "Refs #41"

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "ISSUE_REFERENCE_INVALID",
            ):
                merge_gate.validate(42, self.root)

    def test_04_rejects_multiple_closes_references(self):
        self.pr["body"] = "Closes #41\nCloses #42"

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "ISSUE_REFERENCE_INVALID",
            ):
                merge_gate.validate(42, self.root)

    def test_05_rejects_closed_issue(self):
        self.issue["state"] = "CLOSED"

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "ISSUE_NOT_OPEN",
            ):
                merge_gate.validate(42, self.root)

    def test_06_rejects_active_issue_mismatch(self):
        self.open_issues = [
            {"number": 41, "title": "gate"},
            {"number": 99, "title": "another active issue"},
        ]

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "ACTIVE_ISSUE_MISMATCH",
            ):
                merge_gate.validate(42, self.root)

    def test_07_rejects_missing_evidence(self):
        self.evidence.unlink()

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "EVIDENCE_NOT_FOUND",
            ):
                merge_gate.validate(42, self.root)

    def test_08_rejects_tester_reject(self):
        self.evidence.write_text(
            "Decision: REJECT\n",
            encoding="utf-8",
        )

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "TESTER_REJECTED",
            ):
                merge_gate.validate(42, self.root)

    def test_09_accepts_latest_tester_approve(self):
        self.evidence.write_text(
            (
                "Decision: REJECT\n"
                "Corrections completed.\n"
                "**Decision: APPROVE**\n"
            ),
            encoding="utf-8",
        )

        with self.patched():
            result = merge_gate.validate(
                42,
                self.root,
            )

        self.assertEqual(result["issue_number"], 41)

    def test_10_rejects_missing_required_check(self):
        self.checks = self.checks[:-1]

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "CHECK_MISSING",
            ):
                merge_gate.validate(42, self.root)

    def test_11_rejects_pending_check(self):
        required = merge_gate.REQUIRED_CHECKS[0]

        self.set_check(
            required,
            state="PENDING",
            bucket="pending",
        )

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "CHECK_PENDING",
            ):
                merge_gate.validate(42, self.root)

    def test_12_rejects_failed_check(self):
        required = merge_gate.REQUIRED_CHECKS[0]

        self.set_check(
            required,
            state="FAILURE",
            bucket="fail",
        )

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "CHECK_FAILED",
            ):
                merge_gate.validate(42, self.root)

    def test_13_rejects_cancelled_check(self):
        required = merge_gate.REQUIRED_CHECKS[0]

        self.set_check(
            required,
            state="CANCELLED",
            bucket="cancel",
        )

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "CHECK_CANCELLED",
            ):
                merge_gate.validate(42, self.root)

    def test_14_rejects_skipped_check(self):
        required = merge_gate.REQUIRED_CHECKS[0]

        self.set_check(
            required,
            state="SKIPPED",
            bucket="skipping",
        )

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "CHECK_SKIPPED",
            ):
                merge_gate.validate(42, self.root)

    def test_15_rejects_neutral_check(self):
        required = merge_gate.REQUIRED_CHECKS[0]

        self.set_check(
            required,
            state="NEUTRAL",
            bucket="pass",
        )

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "CHECK_NEUTRAL",
            ):
                merge_gate.validate(42, self.root)

    def test_16_rejects_closed_pr(self):
        self.pr["state"] = "CLOSED"

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "PR_NOT_OPEN",
            ):
                merge_gate.validate(42, self.root)

    def test_17_rejects_unmergeable_pr(self):
        self.pr["mergeable"] = "CONFLICTING"

        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                "PR_NOT_MERGEABLE",
            ):
                merge_gate.validate(42, self.root)

    def test_18_rejects_head_change(self):
        first = dict(self.pr)
        second = dict(self.pr)
        second["headRefOid"] = "new456"

        self.pr_views = [
            first,
            second,
        ]

        with self.patched():
            output = io.StringIO()

            with contextlib.redirect_stdout(output):
                status = merge_gate.main(["42"])

        self.assertEqual(status, 1)
        self.assertIn(
            "MERGE_BLOCKED: HEAD_CHANGED",
            output.getvalue(),
        )
        self.assertEqual(self.merge_commands, [])

    def test_19_rejected_path_never_merges(self):
        self.pr["isDraft"] = True

        with self.patched():
            output = io.StringIO()

            with contextlib.redirect_stdout(output):
                status = merge_gate.main(["42"])

        self.assertEqual(status, 1)
        self.assertEqual(self.merge_commands, [])

    def test_20_green_state_invokes_one_merge(self):
        open_pr_1 = dict(self.pr)
        open_pr_2 = dict(self.pr)

        merged = dict(self.pr)
        merged["state"] = "MERGED"
        merged["mergedAt"] = "2026-09-15T15:30:00Z"

        self.pr_views = [
            open_pr_1,
            open_pr_2,
            merged,
        ]

        with self.patched():
            status = merge_gate.main(["42"])

        self.assertEqual(status, 0)
        self.assertEqual(len(self.merge_commands), 1)

    def test_21_merge_uses_validated_head_sha(self):
        open_pr_1 = dict(self.pr)
        open_pr_2 = dict(self.pr)

        merged = dict(self.pr)
        merged["state"] = "MERGED"
        merged["mergedAt"] = "2026-09-15T15:30:00Z"

        self.pr_views = [
            open_pr_1,
            open_pr_2,
            merged,
        ]

        with self.patched():
            status = merge_gate.main(["42"])

        self.assertEqual(status, 0)

        self.assertEqual(
            self.merge_commands,
            [
                [
                    "pr",
                    "merge",
                    "42",
                    "--merge",
                    "--match-head-commit",
                    "abc123",
                ]
            ],
        )

    def test_22_check_mode_never_merges(self):
        self.pr_views = [
            dict(self.pr),
        ]

        with self.patched():
            output = io.StringIO()

            with contextlib.redirect_stdout(output):
                status = merge_gate.main(
                    ["42", "--check"]
                )

        self.assertEqual(status, 0)
        self.assertIn(
            "MERGE_READY",
            output.getvalue(),
        )
        self.assertEqual(self.merge_commands, [])

    def test_23_no_bypass_cli_options_exist(self):
        parser = merge_gate.build_parser()

        for option in [
            "--force",
            "--skip-ci",
            "--ignore-tester",
            "--ignore-checks",
        ]:
            with self.subTest(option=option):
                with self.assertRaises(SystemExit):
                    parser.parse_args(
                        ["42", option]
                    )


if __name__ == "__main__":
    unittest.main()
