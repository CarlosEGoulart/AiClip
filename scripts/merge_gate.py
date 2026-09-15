#!/usr/bin/env python3
"""Deterministic, fail-closed pull-request merge gate for AiClip."""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]

DEFAULT_BASE_BRANCH = "master"

REQUIRED_CHECKS = (
    "Backend CI / tests",
    "Frontend CI / test",
    "E2E CI / e2e",
    "governance / governance",
    "governance / pr-enforcement",
)

CLOSE_RE = re.compile(r"(?im)^\s*Closes\s+#(\d+)\s*$")

DECISION_RE = re.compile(
    r"(?im)^\s*(?:\*\*)?"
    r"Decision:\s*"
    r"(?:\*\*)?"
    r"(APPROVE|REJECT)"
    r"(?:\*\*)?"
    r"(?:\s.*)?$"
)


class GateBlock(RuntimeError):
    """A deterministic reason why the merge gate must remain closed."""


def gh_json(args: list[str], *, allow_nonzero: bool = False) -> Any:
    """Run GitHub CLI and decode JSON output."""

    process = subprocess.run(
        ["gh", *args],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )

    stdout = process.stdout.strip()

    if process.returncode != 0 and not (allow_nonzero and stdout):
        raise GateBlock("GH_COMMAND_FAILED")

    if not stdout:
        raise GateBlock("GH_EMPTY_RESPONSE")

    try:
        return json.loads(stdout)
    except json.JSONDecodeError as exc:
        raise GateBlock("GH_INVALID_JSON") from exc


def gh_text(args: list[str]) -> str:
    """Run a GitHub CLI mutation command."""

    process = subprocess.run(
        ["gh", *args],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )

    if process.returncode != 0:
        raise GateBlock("GH_MERGE_FAILED")

    return process.stdout.strip()


def fetch_pr(pr_number: int) -> dict[str, Any]:
    return gh_json(
        [
            "pr",
            "view",
            str(pr_number),
            "--json",
            (
                "number,state,isDraft,baseRefName,headRefOid,"
                "mergeable,body,url,mergedAt"
            ),
        ]
    )


def extract_issue_number(body: str) -> int:
    matches = CLOSE_RE.findall(body or "")

    # Exactly one lifecycle-closing reference is required.
    if len(matches) != 1:
        raise GateBlock("ISSUE_REFERENCE_INVALID")

    return int(matches[0])


def validate_pr(pr: dict[str, Any]) -> int:
    state = str(pr.get("state") or "").upper()

    if state != "OPEN":
        raise GateBlock("PR_NOT_OPEN")

    if bool(pr.get("isDraft")):
        raise GateBlock("PR_DRAFT")

    if pr.get("baseRefName") != DEFAULT_BASE_BRANCH:
        raise GateBlock("WRONG_BASE_BRANCH")

    mergeable = str(pr.get("mergeable") or "").upper()

    if mergeable != "MERGEABLE":
        raise GateBlock(f"PR_NOT_MERGEABLE:{mergeable or 'UNKNOWN'}")

    head_sha = str(pr.get("headRefOid") or "").strip()

    if not head_sha:
        raise GateBlock("HEAD_SHA_MISSING")

    return extract_issue_number(str(pr.get("body") or ""))


def validate_issue(issue_number: int) -> None:
    issue = gh_json(
        [
            "issue",
            "view",
            str(issue_number),
            "--json",
            "number,state,title",
        ]
    )

    if str(issue.get("state") or "").upper() != "OPEN":
        raise GateBlock("ISSUE_NOT_OPEN")

    active = gh_json(
        [
            "issue",
            "list",
            "--state",
            "open",
            "--limit",
            "100",
            "--json",
            "number,title",
        ]
    )

    numbers = [int(item["number"]) for item in active]

    if numbers != [issue_number]:
        raise GateBlock("ACTIVE_ISSUE_MISMATCH")


def evidence_path(issue_number: int, root: Path) -> Path:
    matches = sorted(
        root.glob(f"specs/{issue_number:03d}-*/evidence.md")
    )

    if len(matches) != 1:
        raise GateBlock("EVIDENCE_NOT_FOUND")

    return matches[0]


def validate_tester_approval(issue_number: int, root: Path) -> None:
    path = evidence_path(issue_number, root)
    text = path.read_text(encoding="utf-8")

    decisions = DECISION_RE.findall(text)

    if not decisions:
        raise GateBlock("TESTER_NOT_APPROVED")

    final_decision = decisions[-1].upper()

    if final_decision != "APPROVE":
        raise GateBlock("TESTER_REJECTED")


def canonical_check_name(check: dict[str, Any]) -> str:
    workflow = str(check.get("workflow") or "").strip()
    name = str(check.get("name") or "").strip()

    if workflow:
        return f"{workflow} / {name}"

    return name


def validate_required_checks(pr_number: int) -> None:
    checks = gh_json(
        [
            "pr",
            "checks",
            str(pr_number),
            "--json",
            "name,state,bucket,workflow",
        ],
        allow_nonzero=True,
    )

    by_name: dict[str, list[dict[str, Any]]] = {}

    for check in checks:
        name = canonical_check_name(check)
        by_name.setdefault(name, []).append(check)

    for required in REQUIRED_CHECKS:
        entries = by_name.get(required)

        if not entries:
            raise GateBlock(f"CHECK_MISSING:{required}")

        for check in entries:
            bucket = str(check.get("bucket") or "").lower()
            state = str(check.get("state") or "").upper()

            if bucket == "pending":
                raise GateBlock(f"CHECK_PENDING:{required}")

            if bucket == "fail":
                raise GateBlock(f"CHECK_FAILED:{required}")

            if bucket == "cancel":
                raise GateBlock(f"CHECK_CANCELLED:{required}")

            if bucket == "skipping" or state == "SKIPPED":
                raise GateBlock(f"CHECK_SKIPPED:{required}")

            if state == "NEUTRAL":
                raise GateBlock(f"CHECK_NEUTRAL:{required}")

            if bucket != "pass" or state != "SUCCESS":
                raise GateBlock(f"CHECK_NOT_SUCCESS:{required}")


def validate(
    pr_number: int,
    root: Path = ROOT,
) -> dict[str, Any]:
    pr = fetch_pr(pr_number)
    issue_number = validate_pr(pr)

    validate_issue(issue_number)
    validate_tester_approval(issue_number, root)
    validate_required_checks(pr_number)

    return {
        "pr": pr,
        "issue_number": issue_number,
        "head_sha": str(pr["headRefOid"]),
    }


def perform_merge(
    pr_number: int,
    validated_head_sha: str,
) -> None:
    gh_text(
        [
            "pr",
            "merge",
            str(pr_number),
            "--merge",
            "--match-head-commit",
            validated_head_sha,
        ]
    )

    final_pr = fetch_pr(pr_number)

    if not final_pr.get("mergedAt"):
        raise GateBlock("MERGE_NOT_CONFIRMED")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Fail-closed deterministic AiClip PR merge gate."
    )

    parser.add_argument(
        "pr_number",
        type=int,
        help="GitHub Pull Request number",
    )

    parser.add_argument(
        "--check",
        action="store_true",
        help="Validate all merge conditions without merging",
    )

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    try:
        first = validate(args.pr_number, ROOT)

        if args.check:
            print("MERGE_READY")
            return 0

        # Full second validation prevents state changes between the
        # readiness check and mutation.
        second = validate(args.pr_number, ROOT)

        if first["head_sha"] != second["head_sha"]:
            raise GateBlock("HEAD_CHANGED")

        perform_merge(
            args.pr_number,
            first["head_sha"],
        )

        print("MERGED")
        return 0

    except GateBlock as exc:
        print(f"MERGE_BLOCKED: {exc}")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())