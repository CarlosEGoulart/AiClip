"""Governance validators for CI enforcement.

Pure functions that validate commit messages, branch names, PR bodies,
evidence decisions, and TDD sections. No side effects, no network calls.
"""

import re

COMMIT_RE = re.compile(
    r"^(feat|fix|chore|refactor|docs|test|perf|ci)"
    r"\([a-z0-9][a-z0-9-]*\): .+"
)

BRANCH_RE = re.compile(
    r"^@carlosegoulart/(\d+)"
    r"/(feat|fix|chore|refactor|docs|test)"
    r"/([a-z0-9][a-z0-9-]*)$"
)

CLOSES_RE = re.compile(r"closes #\d+", re.IGNORECASE)

REQUIRED_PR_SECTIONS = [
    "## Summary",
    "## Scope",
    "## TDD Evidence",
    "## Tests",
]

DECISION_APPROVE = "Decision: APPROVE"
DECISION_REJECT = "Decision: REJECT"

TDD_HEADINGS = ["### RED", "### GREEN", "### REFACTOR"]
TDD_NA_PATTERN = re.compile(r"TDD:\s*N/A\s*[—-]\s*\S", re.IGNORECASE)


def validate_commit_message(message: str) -> list[str]:
    """Validate Conventional Commit format with mandatory scope.

    Returns empty list for valid messages, list of error strings for invalid.
    """
    errors = []
    if not message or not message.strip():
        errors.append("Commit message must not be empty")
        return errors

    first_line = message.strip().split("\n")[0]

    if not COMMIT_RE.match(first_line):
        if "(" not in first_line.split(":")[0] if ":" in first_line else True:
            errors.append(
                f"Invalid commit format: '{first_line}'. "
                "Must follow <type>(<scope>): <subject>"
            )
        else:
            errors.append(
                f"Invalid commit format: '{first_line}'. "
                "Scope must be lowercase alphanumeric with hyphens"
            )

    return errors


def validate_branch_name(branch: str) -> list[str]:
    """Validate branch naming convention.

    Returns empty list for valid branches, list of error strings for invalid.
    """
    errors = []
    if not branch or not branch.strip():
        errors.append("Branch name must not be empty")
        return errors

    if not BRANCH_RE.match(branch.strip()):
        errors.append(
            f"Invalid branch name: '{branch}'. "
            "Must follow @carlosegoulart/{issue}/{type}/{description}"
        )

    return errors


def validate_pr_body(body: str) -> list[str]:
    """Validate PR body has Closes #N and required sections.

    Returns empty list for valid bodies, list of error strings for invalid.
    """
    errors = []
    if not body or not body.strip():
        errors.append("PR body must not be empty")
        return errors

    if not CLOSES_RE.search(body):
        errors.append("PR body must contain 'Closes #N' reference")

    for section in REQUIRED_PR_SECTIONS:
        if section not in body:
            errors.append(f"PR body must contain '{section}' section")

    return errors


def validate_evidence_decision(content: str) -> list[str]:
    """Validate evidence file has explicit Tester decision.

    Returns empty list for valid content, list of error strings for invalid.
    """
    errors = []
    if not content or not content.strip():
        errors.append("Evidence content must not be empty")
        return errors

    has_approve = DECISION_APPROVE in content
    has_reject = DECISION_REJECT in content

    if has_approve and has_reject:
        errors.append("Evidence must contain exactly one of APPROVE or REJECT, not both")
    elif not has_approve and not has_reject:
        errors.append(
            "Evidence must contain 'Decision: APPROVE' or 'Decision: REJECT'"
        )

    return errors


def validate_tdd_sections(content: str) -> list[str]:
    """Validate TDD evidence structure.

    Returns empty list for valid content, list of error strings for invalid.
    """
    errors = []
    if not content or not content.strip():
        errors.append("Evidence content must not be empty")
        return errors

    has_all_headings = all(h in content for h in TDD_HEADINGS)
    has_na = TDD_NA_PATTERN.search(content)

    if not has_all_headings and not has_na:
        errors.append(
            "Evidence must contain '### RED', '### GREEN', '### REFACTOR' "
            "sections or explicit 'TDD: N/A — <reason>'"
        )

    return errors


def validate_merge_approval(content: str) -> list[str]:
    """Validate evidence file has explicit APPROVE decision for merge gate.

    Returns empty list for APPROVE, list of error strings for REJECT, missing, or conflicting.
    """
    errors = []
    if not content or not content.strip():
        errors.append("Evidence content must not be empty")
        return errors

    has_approve = DECISION_APPROVE in content
    has_reject = DECISION_REJECT in content

    if has_approve and has_reject:
        errors.append("Evidence must contain exactly one decision, not both APPROVE and REJECT")
    elif has_reject:
        errors.append("Merge gate requires 'Decision: APPROVE', found 'Decision: REJECT'")
    elif not has_approve:
        errors.append("Merge gate requires 'Decision: APPROVE' in evidence file")

    return errors
