#!/usr/bin/env python3
"""Deterministic preflight validation for AiClip governance.

Catches common workflow and governance violations before expensive tests or
pull-request review. Pure functions, no side effects, no network, no secrets.

Usage:
    python scripts/preflight.py

Exit codes:
    0 — all rules passed
    non-zero — one or more rules failed (each failure printed with remediation)
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPECS_DIR = ROOT / "specs"

BRANCH_RE = re.compile(
    r"^@carlosegoulart/"
    r"(?P<issue>\d+)/"
    r"(?P<type>feat|fix|chore|refactor|docs|test)/"
    r"(?P<desc>[a-z0-9][a-z0-9-]*)$"
)

CONVENTIONAL_COMMIT_RE = re.compile(
    r"^(?P<type>feat|fix|chore|refactor|docs|test|perf|ci)"
    r"(\((?P<scope>[a-z0-9][a-z0-9-]*)\))?: (?P<subject>.+)$"
)

GENERATED_ARTIFACT_PATTERNS = [
    "node_modules",
    "dist",
    "build",
    "__pycache__",
    ".pytest_cache",
    ".env",
]


def rule_p1_branch_issue(branch: str) -> tuple[str, str | None, str | None]:
    """Validate branch name against @carlosegoulart/{n}/{type}/{desc}
    and association with an existing specs directory."""
    if not branch or not branch.strip():
        return (
            "P1",
            "Branch name is empty.",
            "Create a branch named @carlosegoulart/{issue}/{type}/{description}.",
        )

    m = BRANCH_RE.match(branch.strip())
    if not m:
        return (
            "P1",
            f"Branch name '{branch}' does not match "
            "@carlosegoulart/{issue}/{type}/{description}.",
            "Rename the branch to @carlosegoulart/{issue}/{type}/{description}.",
        )

    issue_num = int(m.group("issue"))
    prefix = f"{issue_num:03d}-"
    match_dir = None
    if SPECS_DIR.exists():
        for d in sorted(SPECS_DIR.iterdir()):
            if d.is_dir() and d.name.startswith(prefix):
                match_dir = d
                break

    if match_dir is None:
        return (
            "P1",
            f"No specs directory found for issue #{issue_num}.",
            f"Create specs/{issue_num:03d}-<slug>/ with spec.md, plan.md, test-plan.md.",
        )

    return ("P1", None, None)


def rule_p2_spec_bundle(issue_num: int) -> tuple[str, str | None, str | None]:
    """Verify required spec bundle files exist for the active issue."""
    prefix = f"{issue_num:03d}-"
    issue_dir = None
    if SPECS_DIR.exists():
        for d in sorted(SPECS_DIR.iterdir()):
            if d.is_dir() and d.name.startswith(prefix):
                issue_dir = d
                break

    if issue_dir is None:
        return (
            "P2",
            f"No specs directory found for issue #{issue_num}.",
            f"Create specs/{issue_num:03d}-<slug>/ with spec.md, plan.md, test-plan.md.",
        )

    required = ["spec.md", "plan.md", "test-plan.md"]
    for fname in required:
        path = issue_dir / fname
        if not path.exists():
            return (
                "P2",
                f"Missing required file {fname} in {issue_dir.name}.",
                f"Create {issue_dir.name}/{fname} per AGENTS.md.",
            )
        if not path.is_file() or path.stat().st_size == 0:
            return (
                "P2",
                f"Required file {fname} in {issue_dir.name} is empty or not a regular file.",
                f"Populate {issue_dir.name}/{fname} with valid content.",
            )

    return ("P2", None, None)


def rule_p3_working_tree() -> tuple[str, str | None, str | None]:
    """Verify working-tree hygiene: no uncommitted changes outside
    the active issue's spec bundle files."""
    try:
        result = subprocess.run(
            ["git", "status", "--porcelain"],
            capture_output=True,
            text=True,
            cwd=ROOT,
        )
    except FileNotFoundError:
        return ("P3", "git is not available.", "Install git and retry.")

    if result.returncode != 0:
        return ("P3", "git status failed.", "Ensure you are inside a git repository.")

    active_spec_files = _active_spec_bundle_files()

    for line in result.stdout.splitlines():
        if not line.strip():
            continue
        # porcelain format: XY path or XY -> path (renames)
        # Skip untracked files (?? prefix) — P3 covers tracked files only.
        if line.startswith("??"):
            continue
        parts = line.split()
        if len(parts) >= 2:
            filepath = parts[-1]
            if filepath.startswith('"'):
                filepath = filepath.strip('"')
        else:
            continue

        if filepath in active_spec_files:
            continue

        return (
            "P3",
            f"Unrelated uncommitted change: {filepath}.",
            f"Commit or stash {filepath} before running preflight.",
        )

    return ("P3", None, None)


def rule_p4_generated_artifacts() -> tuple[str, str | None, str | None]:
    """Detect generated/build artifacts that must not be committed."""
    for pattern in GENERATED_ARTIFACT_PATTERNS:
        for path in ROOT.rglob(pattern):
            if path.is_dir() or path.is_file():
                if ".git" in path.parts:
                    continue
                return (
                    "P4",
                    f"Generated artifact present: {path.relative_to(ROOT)}.",
                    f"Remove or .gitignore {path.relative_to(ROOT)}.",
                )

    return ("P4", None, None)


def rule_p5_commit_messages(branch: str) -> tuple[str, str | None, str | None]:
    """Verify commit messages on the branch follow Conventional Commits."""
    if not branch or not branch.strip():
        return ("P5", None, None)

    try:
        base_ref = "master"
        result = subprocess.run(
            ["git", "log", "--format=%s", "--no-merges", f"origin/{base_ref}..HEAD"],
            capture_output=True,
            text=True,
            cwd=ROOT,
        )
    except FileNotFoundError:
        return ("P5", "git is not available.", "Install git and retry.")

    if result.returncode != 0:
        return ("P5", None, None)

    for msg in result.stdout.strip().splitlines():
        if not msg.strip():
            continue
        if not CONVENTIONAL_COMMIT_RE.match(msg.strip()):
            return (
                "P5",
                f"Non-conventional commit message: '{msg}'.",
                f"Reword to <type>(<scope>): <subject> with allowed types "
                f"(feat, fix, chore, refactor, docs, test, perf, ci).",
            )

    return ("P5", None, None)


def _active_spec_bundle_files() -> set[str]:
    """Return the set of spec bundle file paths allowed to be uncommitted
    for the active issue (Planner-authored files)."""
    active = set()
    try:
        result = subprocess.run(
            ["git", "branch", "--show-current"],
            capture_output=True,
            text=True,
            cwd=ROOT,
        )
        if result.returncode == 0:
            branch = result.stdout.strip()
            m = BRANCH_RE.match(branch)
            if m:
                issue_num = int(m.group("issue"))
                prefix = f"{issue_num:03d}-"
                if SPECS_DIR.exists():
                    for d in sorted(SPECS_DIR.iterdir()):
                        if d.is_dir() and d.name.startswith(prefix):
                            for fname in ["spec.md", "plan.md", "test-plan.md"]:
                                active.add(str(d / fname))
                            break
    except FileNotFoundError:
        pass
    return active


def run_preflight(branch: str | None = None) -> list[str]:
    """Run all preflight rules and return a list of error strings."""
    errors: list[str] = []

    if branch is None:
        try:
            result = subprocess.run(
                ["git", "branch", "--show-current"],
                capture_output=True,
                text=True,
                cwd=ROOT,
            )
            branch = result.stdout.strip() if result.returncode == 0 else ""
        except FileNotFoundError:
            branch = ""

    _, err, rem = rule_p1_branch_issue(branch)
    if err:
        errors.append(f"P1: {err} Remediation: {rem}")
        return errors

    m = BRANCH_RE.match(branch.strip())
    issue_num = int(m.group("issue")) if m else None

    if issue_num is not None:
        _, err, rem = rule_p2_spec_bundle(issue_num)
        if err:
            errors.append(f"P2: {err} Remediation: {rem}")

    _, err, rem = rule_p3_working_tree()
    if err:
        errors.append(f"P3: {err} Remediation: {rem}")

    _, err, rem = rule_p4_generated_artifacts()
    if err:
        errors.append(f"P4: {err} Remediation: {rem}")

    _, err, rem = rule_p5_commit_messages(branch)
    if err:
        errors.append(f"P5: {err} Remediation: {rem}")

    return errors


def main() -> int:
    """CLI entry point."""
    errors = run_preflight()

    if errors:
        print("Preflight checks failed:")
        for e in errors:
            print(f"  - {e}")
        return 1

    print("Preflight checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
