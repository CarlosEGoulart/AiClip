"""Regression tests for scripts/merge_gate.py."""

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
MERGE_GATE_PATH = (
    REPO_ROOT
    / "scripts"
    / "merge_gate.py"
)

SPEC = importlib.util.spec_from_file_location(
    "aiclip_merge_gate",
    MERGE_GATE_PATH,
)

if SPEC is None or SPEC.loader is None:
    raise RuntimeError(
        "Unable to load merge_gate.py"
    )

merge_gate = importlib.util.module_from_spec(
    SPEC
)

sys.modules[SPEC.name] = merge_gate
SPEC.loader.exec_module(merge_gate)


class TestMergeGate(unittest.TestCase):
    def setUp(self):
        self.temp = (
            tempfile.TemporaryDirectory()
        )

        self.root = Path(
            self.temp.name
        )

        issue_dir = (
            self.root
            / "specs"
            / "043-simplify-agent-control-plane"
        )

        issue_dir.mkdir(
            parents=True
        )

        self.evidence = (
            issue_dir
            / "evidence.md"
        )

        self.evidence.write_text(
            "Decision: APPROVE\n",
            encoding="utf-8",
        )

        self.pr = {
            "number": 44,
            "state": "OPEN",
            "isDraft": False,
            "baseRefName": "master",
            "headRefName": (
                "@carlosegoulart/43/"
                "refactor/"
                "simplify-agent-control-plane"
            ),
            "headRefOid": "abc123",
            "mergeable": "MERGEABLE",
            "body": "Closes #43",
            "mergedAt": None,
        }

        self.issue = {
            "number": 43,
            "state": "OPEN",
            "title": "simplify agents",
        }

        self.checks = []

        for required in (
            merge_gate.REQUIRED_CHECKS
        ):
            workflow, name = (
                required.split(
                    " / ",
                    1,
                )
            )

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

    def fake_json(
        self,
        args,
        *,
        allow_nonzero_with_output=False,
    ):
        if args[:2] == [
            "pr",
            "view",
        ]:
            if self.pr_views:
                return self.pr_views.pop(0)

            return dict(self.pr)

        if args[:2] == [
            "issue",
            "view",
        ]:
            return dict(self.issue)

        if args[:2] == [
            "pr",
            "checks",
        ]:
            return [
                dict(item)
                for item in self.checks
            ]

        raise AssertionError(
            f"Unexpected gh call: {args}"
        )

    def fake_text(self, args):
        self.merge_commands.append(
            list(args)
        )

        return "merged"

    @contextlib.contextmanager
    def patched(self):
        with (
            patch.object(
                merge_gate,
                "run_gh_json",
                side_effect=self.fake_json,
            ),
            patch.object(
                merge_gate,
                "run_gh_text",
                side_effect=self.fake_text,
            ),
            patch.object(
                merge_gate,
                "ROOT",
                self.root,
            ),
        ):
            yield

    def set_check(
        self,
        canonical,
        *,
        state,
        bucket,
    ):
        for check in self.checks:
            current = (
                f"{check['workflow']} / "
                f"{check['name']}"
            )

            if current == canonical:
                check["state"] = state
                check["bucket"] = bucket
                return

        raise AssertionError(
            f"Unknown check {canonical}"
        )

    def assert_blocked(
        self,
        reason,
    ):
        with self.patched():
            with self.assertRaisesRegex(
                merge_gate.GateBlock,
                reason,
            ):
                merge_gate.validate(
                    44,
                    self.root,
                )

    def test_draft_blocks(self):
        self.pr["isDraft"] = True
        self.assert_blocked("PR_DRAFT")

    def test_wrong_base_blocks(self):
        self.pr["baseRefName"] = "develop"
        self.assert_blocked(
            "WRONG_BASE_BRANCH"
        )

    def test_invalid_branch_blocks(self):
        self.pr["headRefName"] = (
            "feature/test"
        )
        self.assert_blocked(
            "INVALID_HEAD_BRANCH"
        )

    def test_branch_issue_mismatch_blocks(self):
        self.pr["headRefName"] = (
            "@carlosegoulart/99/"
            "fix/test"
        )
        self.assert_blocked(
            "BRANCH_ISSUE_MISMATCH"
        )

    def test_missing_closes_blocks(self):
        self.pr["body"] = "Refs #43"
        self.assert_blocked(
            "ISSUE_REFERENCE_INVALID"
        )

    def test_multiple_closes_blocks(self):
        self.pr["body"] = (
            "Closes #43\nCloses #44"
        )
        self.assert_blocked(
            "ISSUE_REFERENCE_INVALID"
        )

    def test_closed_issue_blocks(self):
        self.issue["state"] = "CLOSED"
        self.assert_blocked(
            "ISSUE_NOT_OPEN"
        )

    def test_missing_evidence_blocks(self):
        self.evidence.unlink()
        self.assert_blocked(
            "EVIDENCE_NOT_FOUND"
        )

    def test_missing_tester_decision_blocks(self):
        self.evidence.write_text(
            "Tester pending\n",
            encoding="utf-8",
        )

        self.assert_blocked(
            "TESTER_NOT_APPROVED"
        )

    def test_tester_reject_blocks(self):
        self.evidence.write_text(
            "Decision: REJECT\n",
            encoding="utf-8",
        )

        self.assert_blocked(
            "TESTER_REJECTED"
        )

    def test_latest_approve_wins(self):
        self.evidence.write_text(
            (
                "Decision: REJECT\n"
                "fixed\n"
                "Decision: APPROVE\n"
            ),
            encoding="utf-8",
        )

        with self.patched():
            result = merge_gate.validate(
                44,
                self.root,
            )

        self.assertEqual(
            result["issue_number"],
            43,
        )

    def test_missing_required_check_blocks(self):
        self.checks.pop()
        self.assert_blocked(
            "CHECK_MISSING"
        )

    def test_pending_check_blocks(self):
        self.set_check(
            merge_gate.REQUIRED_CHECKS[0],
            state="PENDING",
            bucket="pending",
        )
        self.assert_blocked(
            "CHECK_PENDING"
        )

    def test_failed_check_blocks(self):
        self.set_check(
            merge_gate.REQUIRED_CHECKS[0],
            state="FAILURE",
            bucket="fail",
        )
        self.assert_blocked(
            "CHECK_FAILED"
        )

    def test_cancelled_check_blocks(self):
        self.set_check(
            merge_gate.REQUIRED_CHECKS[0],
            state="CANCELLED",
            bucket="cancel",
        )
        self.assert_blocked(
            "CHECK_CANCELLED"
        )

    def test_skipped_check_blocks(self):
        self.set_check(
            merge_gate.REQUIRED_CHECKS[0],
            state="SKIPPED",
            bucket="skipping",
        )
        self.assert_blocked(
            "CHECK_SKIPPED"
        )

    def test_neutral_check_blocks(self):
        self.set_check(
            merge_gate.REQUIRED_CHECKS[0],
            state="NEUTRAL",
            bucket="pass",
        )
        self.assert_blocked(
            "CHECK_NEUTRAL"
        )

    def test_unknown_state_blocks(self):
        self.set_check(
            merge_gate.REQUIRED_CHECKS[0],
            state="UNKNOWN",
            bucket="unknown",
        )
        self.assert_blocked(
            "CHECK_NOT_SUCCESS"
        )

    def test_closed_pr_blocks(self):
        self.pr["state"] = "CLOSED"
        self.assert_blocked(
            "PR_NOT_OPEN"
        )

    def test_conflicting_pr_blocks(self):
        self.pr["mergeable"] = (
            "CONFLICTING"
        )

        self.assert_blocked(
            "PR_NOT_MERGEABLE"
        )

    def test_check_mode_does_not_merge(self):
        with self.patched():
            output = io.StringIO()

            with contextlib.redirect_stdout(
                output
            ):
                status = merge_gate.main(
                    ["44", "--check"]
                )

        self.assertEqual(status, 0)
        self.assertIn(
            "MERGE_READY",
            output.getvalue(),
        )
        self.assertEqual(
            self.merge_commands,
            [],
        )

    def test_head_change_blocks_merge(self):
        first = dict(self.pr)
        second = dict(self.pr)

        second["headRefOid"] = "new456"

        self.pr_views = [
            first,
            second,
        ]

        with self.patched():
            output = io.StringIO()

            with contextlib.redirect_stdout(
                output
            ):
                status = merge_gate.main(
                    ["44"]
                )

        self.assertEqual(status, 1)

        self.assertIn(
            "HEAD_CHANGED",
            output.getvalue(),
        )

        self.assertEqual(
            self.merge_commands,
            [],
        )

    def test_green_path_merges_once_with_sha(self):
        first = dict(self.pr)
        second = dict(self.pr)

        merged = dict(self.pr)
        merged["state"] = "MERGED"
        merged["mergedAt"] = (
            "2026-09-15T18:00:00Z"
        )

        self.pr_views = [
            first,
            second,
            merged,
        ]

        with self.patched():
            status = merge_gate.main(
                ["44"]
            )

        self.assertEqual(status, 0)

        self.assertEqual(
            self.merge_commands,
            [
                [
                    "pr",
                    "merge",
                    "44",
                    "--merge",
                    "--match-head-commit",
                    "abc123",
                ]
            ],
        )

    def test_no_bypass_options_exist(self):
        parser = merge_gate.build_parser()

        for option in [
            "--force",
            "--skip-ci",
            "--ignore-ci",
            "--ignore-tester",
            "--ignore-checks",
        ]:
            with self.subTest(
                option=option
            ):
                with self.assertRaises(
                    SystemExit
                ):
                    parser.parse_args(
                        [
                            "44",
                            option,
                        ]
                    )


if __name__ == "__main__":
    unittest.main()