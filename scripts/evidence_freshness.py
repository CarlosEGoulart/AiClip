#!/usr/bin/env python3
"""Deterministic evidence freshness and traceability validator.

Validates that evidence.md records are bound to the exact commit/PR state,
fail closed on stale/missing/contradictory evidence, and standardize
decision-line validation. Pure functions, no network, no secrets.

Usage:
    python scripts/evidence_freshness.py <evidence_path> <head_sha> <pr_url>
        [check1:state] [check2:state] ...

Exit codes:
    0 — all freshness checks passed
    non-zero — one or more checks failed (each failure printed with remediation)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# Required CI checks from scripts/merge_gate.py REQUIRED_CHECKS
REQUIRED_CHECKS = (
    "Backend CI / tests",
    "Frontend CI / test",
    "E2E CI / e2e",
    "governance / governance",
    "governance / pr-enforcement",
)

DECISION_RE = re.compile(
    r"(?im)^\s*(?:\*\*)?"
    r"Decision:\s*"
    r"(?:\*\*)?"
    r"(APPROVE|REJECT)"
    r"(?:\*\*)?"
    r"(?:\s.*)?$"
)

SHA_RE = re.compile(r"^[0-9a-f]{40}$")

EVIDENCE_FIELD_RE = re.compile(
    r"(?im)^\s*(Decision|Commit|PR|CI|Evidence-At|Merge-State)\s*:\s*(.*)$"
)


# ---------------------------------------------------------------------------
# Parsing
# ---------------------------------------------------------------------------


def parse_evidence(content: str) -> dict:
    """Extract evidence fields from evidence.md content.

    Returns a dict with keys:
        decision: str | None        — APPROVE, REJECT, or None
        decision_lines: int         — count of Decision: lines found
        commit: str | None          — commit SHA or None
        pr: str | None              — PR URL or None
        ci: dict[str, str]          — check name -> state mapping
        evidence_at: str | None     — ISO-8601 timestamp or None
        merge_state: str | None     — open, merged, closed, or None
    """
    if not content or not content.strip():
        return {
            "decision": None,
            "decision_lines": 0,
            "commit": None,
            "pr": None,
            "ci": {},
            "evidence_at": None,
            "merge_state": None,
            "empty": True,
        }

    decision = None
    decision_lines = 0
    commit = None
    pr = None
    ci = {}
    evidence_at = None
    merge_state = None

    for line in content.splitlines():
        m = EVIDENCE_FIELD_RE.match(line)
        if not m:
            continue

        field = m.group(1)
        value = m.group(2).strip()

        if field == "Decision":
            decision_lines += 1
            dm = DECISION_RE.match(line)
            if dm:
                decision = dm.group(1).upper()
        elif field == "Commit":
            commit = value
        elif field == "PR":
            pr = value
        elif field == "CI":
            # Format: CI: <check-name>: <state>
            cm = re.match(r"^(?P<name>.+):\s*(?P<state>\w+)$", value)
            if cm:
                ci[cm.group("name").strip()] = cm.group("state").strip().lower()
        elif field == "Evidence-At":
            evidence_at = value
        elif field == "Merge-State":
            merge_state = value.strip().lower()

    return {
        "decision": decision,
        "decision_lines": decision_lines,
        "commit": commit,
        "pr": pr,
        "ci": ci,
        "evidence_at": evidence_at,
        "merge_state": merge_state,
        "empty": False,
    }


# ---------------------------------------------------------------------------
# Validation rules (each returns (rule_id, error_or_None, remediation_or_None))
# ---------------------------------------------------------------------------


def validate_decision_line(parsed: dict) -> tuple:
    """Exactly one valid Decision: APPROVE|REJECT line."""
    if parsed["empty"]:
        return (
            "Decision",
            "Evidence content is empty.",
            "Add a Decision: APPROVE or Decision: REJECT line to the evidence file.",
        )

    if parsed["decision_lines"] == 0:
        return (
            "Decision",
            "Missing Decision line.",
            "Add exactly one Decision: APPROVE or Decision: REJECT line.",
        )

    if parsed["decision_lines"] > 1:
        return (
            "Decision",
            f"Multiple Decision lines found ({parsed['decision_lines']}). "
            "Conflicting or multiple official decisions are not allowed.",
            "Keep exactly one Decision: APPROVE or Decision: REJECT line.",
        )

    if parsed["decision"] is None:
        return (
            "Decision",
            "Malformed Decision line (not APPROVE or REJECT).",
            "Use Decision: APPROVE or Decision: REJECT only.",
        )

    return ("Decision", None, None)


def validate_commit(parsed: dict, head_sha: str) -> tuple:
    """Evidence Commit SHA must match the evaluated head SHA."""
    if not parsed["commit"]:
        return (
            "Commit",
            "Missing Commit field.",
            "Add Commit: <40-hex-sha> to the evidence file.",
        )

    if not SHA_RE.match(parsed["commit"]):
        return (
            "Commit",
            f"Invalid Commit SHA format: '{parsed['commit']}'.",
            "Use a full 40-hex-character commit SHA.",
        )

    if parsed["commit"].lower() != head_sha.lower():
        return (
            "Commit",
            f"Stale commit SHA: evidence says {parsed['commit']}, "
            f"expected {head_sha}.",
            f"Update Commit: to {head_sha} (current branch head).",
        )

    return ("Commit", None, None)


def validate_pr(parsed: dict, pr_url: str) -> tuple:
    """Evidence PR URL must match the evaluated PR."""
    if not parsed["pr"]:
        return (
            "PR",
            "Missing PR field.",
            "Add PR: <https://github.com/<owner>/<repo>/pull/<n>> to evidence.",
        )

    if parsed["pr"].rstrip("/") != pr_url.rstrip("/"):
        return (
            "PR",
            f"Stale PR URL: evidence says {parsed['pr']}, expected {pr_url}.",
            f"Update PR: to {pr_url} (current PR being evaluated).",
        )

    return ("PR", None, None)


def validate_evidence_at(parsed: dict) -> tuple:
    """Evidence-At timestamp must be present."""
    if not parsed["evidence_at"]:
        return (
            "Evidence-At",
            "Missing Evidence-At timestamp.",
            "Add Evidence-At: <ISO-8601 UTC> to the evidence file.",
        )

    return ("Evidence-At", None, None)


def validate_ci_checks(parsed: dict, observed_checks: dict) -> tuple:
    """Every required CI check present in evidence, state matches, no fabricated."""
    evidence_ci = parsed["ci"]

    # Check each REQUIRED_CHECK is present in evidence
    for check_name in REQUIRED_CHECKS:
        if check_name not in evidence_ci:
            return (
                "CI",
                f"Missing CI check: {check_name}.",
                f"Add CI: {check_name}: <pass|fail|pending> to evidence.",
            )

    # Check every evidence CI entry matches observed state (no fabricated)
    for check_name, evidence_state in evidence_ci.items():
        if check_name not in observed_checks:
            return (
                "CI",
                f"Fabricated CI check marker: {check_name} was not run.",
                f"Remove CI: {check_name} line or run the check first.",
            )

        observed_state = observed_checks[check_name].lower()
        if evidence_state != observed_state:
            return (
                "CI",
                f"CI check state mismatch: {check_name} evidence says "
                f"{evidence_state}, observed {observed_state}.",
                f"Update CI: {check_name} to {observed_state}.",
            )

    return ("CI", None, None)


# ---------------------------------------------------------------------------
# Aggregator
# ---------------------------------------------------------------------------


def validate_freshness(content, head_sha, pr_url, observed_checks):
    """Run all evidence freshness rules and return a list of error strings."""
    errors = []
    parsed = parse_evidence(content)

    # Decision line (must be valid before dependent checks)
    _, err, rem = validate_decision_line(parsed)
    if err:
        errors.append(f"Decision: {err} Remediation: {rem}")
        return errors  # fail closed; cannot trust further fields

    # Required evidence fields
    _, err, rem = validate_commit(parsed, head_sha)
    if err:
        errors.append(f"Commit: {err} Remediation: {rem}")

    _, err, rem = validate_pr(parsed, pr_url)
    if err:
        errors.append(f"PR: {err} Remediation: {rem}")

    _, err, rem = validate_evidence_at(parsed)
    if err:
        errors.append(f"Evidence-At: {err} Remediation: {rem}")

    # CI checks
    _, err, rem = validate_ci_checks(parsed, observed_checks)
    if err:
        errors.append(f"CI: {err} Remediation: {rem}")

    return errors


def run_evidence_freshness(evidence_path, head_sha, pr_url, observed_checks):
    """Entry point: read evidence file, run freshness validation."""
    try:
        content = Path(evidence_path).read_text()
    except FileNotFoundError:
        return 1, [
            f"Evidence: Evidence file not found: {evidence_path}. "
            "Remediation: create the evidence.md file for this issue."
        ]
    except Exception as exc:
        return 1, [
            f"Evidence: Could not read evidence file: {exc}. "
            "Remediation: verify the evidence file path and permissions."
        ]

    errors = validate_freshness(content, head_sha, pr_url, observed_checks)
    return (1 if errors else 0), errors


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def parse_cli_observed_checks(args):
    """Parse CLI arguments of form check:state into a dict."""
    checks = {}
    for arg in args:
        if ":" not in arg:
            continue
        name, state = arg.split(":", 1)
        checks[name.strip()] = state.strip().lower()
    return checks


def main(argv=None):
    """CLI entry point."""
    if argv is None:
        argv = sys.argv[1:]

    if len(argv) < 3:
        print(
            "Usage: python scripts/evidence_freshness.py "
            "<evidence_path> <head_sha> <pr_url> [check:state] ...",
            file=sys.stderr,
        )
        return 2

    evidence_path = argv[0]
    head_sha = argv[1]
    pr_url = argv[2]
    observed_checks = parse_cli_observed_checks(argv[3:])

    exit_code, errors = run_evidence_freshness(
        evidence_path, head_sha, pr_url, observed_checks
    )

    if errors:
        print("Evidence freshness checks failed:")
        for e in errors:
            print(f"  - {e}")
        return exit_code

    print("Evidence freshness checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
