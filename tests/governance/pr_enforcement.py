"""PR governance enforcement orchestration.

Reads GitHub event payload and invokes all five validators.
Intended to be called from GitHub Actions CI.
"""

import json
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from validators import (
    validate_branch_name,
    validate_commit_message,
    validate_merge_approval,
    validate_pr_body,
    validate_tdd_sections,
)

CLOSES_RE = re.compile(r"closes #(\d+)", re.IGNORECASE)
BRANCH_ISSUE_RE = re.compile(r"@carlosegoulart/(\d+)/")


def load_event_payload(path: str) -> dict:
    """Load GitHub event payload from file."""
    with open(path) as f:
        return json.load(f)


def get_pr_body(event: dict) -> str:
    """Extract PR body from event payload."""
    return event.get("pull_request", {}).get("body", "")


def get_pr_number(event: dict) -> int:
    """Extract PR number from event payload."""
    return event.get("pull_request", {}).get("number", 0)


def get_branch_name() -> str:
    """Read branch name from GITHUB_HEAD_REF."""
    return os.environ.get("GITHUB_HEAD_REF", "")


def get_commit_messages(base_ref: str) -> list[str]:
    """Get non-merge commit messages from PR."""
    result = subprocess.run(
        ["git", "log", "--format=%s", "--no-merges", f"origin/{base_ref}..HEAD"],
        capture_output=True,
        text=True,
    )
    return [m for m in result.stdout.strip().split("\n") if m.strip()]


def extract_branch_issue(branch: str) -> int | None:
    """Extract issue number from branch name."""
    m = BRANCH_ISSUE_RE.search(branch)
    return int(m.group(1)) if m else None


def extract_closes_issues(body: str) -> list[int]:
    """Extract issue numbers from Closes #N references in PR body."""
    return [int(m.group(1)) for m in CLOSES_RE.finditer(body)]


def find_evidence_file(issue_number: int, specs_dir: Path | None = None) -> Path | None:
    """Find evidence.md for the given issue number.

    Returns None if no match or multiple matches (ambiguous).
    Returns the evidence path only if exactly one match exists.
    """
    if specs_dir is None:
        specs_dir = Path(__file__).resolve().parents[2] / "specs"
    if not specs_dir.exists():
        return None
    prefix = f"{issue_number:03d}"
    matches = []
    for d in sorted(specs_dir.iterdir()):
        if d.is_dir() and d.name.startswith(prefix):
            evidence = d / "evidence.md"
            if evidence.exists():
                matches.append(evidence)
    if len(matches) == 1:
        return matches[0]
    return None


def run_checks() -> list[str]:
    """Run all governance checks and return list of errors."""
    errors = []

    # Load event payload
    event_path = os.environ.get("GITHUB_EVENT_PATH")
    if not event_path or not Path(event_path).exists():
        errors.append("GITHUB_EVENT_PATH not set or file not found")
        return errors

    event = load_event_payload(event_path)
    pr_body = get_pr_body(event)

    # Validate commit messages
    base_ref = os.environ.get("GITHUB_BASE_REF", "master")
    for msg in get_commit_messages(base_ref):
        errors.extend(validate_commit_message(msg))

    # Validate branch name
    branch = get_branch_name()
    if branch:
        errors.extend(validate_branch_name(branch))

    # Validate PR body structure
    errors.extend(validate_pr_body(pr_body))

    # Cross-validate branch issue vs Closes issue
    branch_issue = extract_branch_issue(branch) if branch else None
    closes_issues = extract_closes_issues(pr_body)

    if branch_issue is not None and closes_issues:
        if len(closes_issues) > 1:
            errors.append(
                f"PR must reference exactly one issue, found {len(closes_issues)}: "
                + ", ".join(f"#{i}" for i in closes_issues)
            )
        elif closes_issues[0] != branch_issue:
            errors.append(
                f"Branch issue #{branch_issue} does not match PR Closes #{closes_issues[0]}"
            )

    # Validate evidence file
    if branch_issue is not None:
        evidence_path = find_evidence_file(branch_issue)
        if evidence_path is None:
            errors.append(f"No evidence.md found for issue #{branch_issue}")
        else:
            content = evidence_path.read_text()
            errors.extend(validate_merge_approval(content))
            errors.extend(validate_tdd_sections(content))

    return errors


def main():
    """Main entry point for CI."""
    errors = run_checks()
    if errors:
        for e in errors:
            print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)
    print("PR enforcement checks passed")


if __name__ == "__main__":
    main()
