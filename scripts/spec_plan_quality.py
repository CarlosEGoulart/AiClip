#!/usr/bin/env python3
"""Deterministic spec-plan quality validator.

Enforces a versioned objective contract over spec.md, plan.md, and test-plan.md,
detecting missing files/sections, placeholders, empty sections, non-objective
acceptance criteria, and contradictory requirements — without rewriting specs
or making subjective implementation decisions.

Usage:
    python scripts/spec_plan_quality.py <specs_dir> <issue_number>

Exit codes:
    0 — all quality checks passed
    non-zero — one or more checks failed (each failure printed with remediation)
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

CONTRACT_VERSION = "spec-plan-quality v1.0"

REQUIRED_FILES = ["spec.md", "plan.md", "test-plan.md"]

REQUIRED_SECTIONS = {
    "spec.md": [
        "# Title",
        "## Description",
        "## Acceptance Criteria",
        "## Out of Scope",
        "## Security Considerations",
        "## Test Scenarios",
        "## Dependencies",
    ],
    "plan.md": [
        "## Objective",
        "## Phase",
        "## Commits",
        "## Verification",
    ],
    "test-plan.md": [
        "## Overview",
        "## Test Scenarios",
        "## Application E2E / Playwright",
        "## Test Execution",
    ],
}

ACCEPTANCE_CHECKLIST_RE = re.compile(r"^-\s*\[[ xX]\]\s+.+$", re.IGNORECASE)

SUBJECTIVE_ADJECTIVES = [
    "good",
    "better",
    "best",
    "fast",
    "faster",
    "fastest",
    "slow",
    "slower",
    "slowest",
    "nice",
    "smooth",
    "intuitive",
    "clean",
    "polished",
    "robust",
    "stable",
    "scalable",
    "performant",
    "seamless",
    "elegant",
    "simple",
    "easy",
    "user-friendly",
]

PLACEHOLDER_TOKENS = [
    "TODO",
    "FIXME",
    "TBD",
    "XXX",
    "PLACEHOLDER",
    "LOREM IPSUM",
    "TO BE DECIDED",
    "todo",
    "fixme",
    "tbd",
    "xxx",
    "placeholder",
    "lorem ipsum",
    "to be decided",
]

CODE_FENCE_RE = re.compile(r"^```")


def _heading_text(line: str) -> str:
    """Return the heading text without leading # markers, lowercased."""
    stripped = line.strip()
    if stripped.startswith("#"):
        return stripped.lstrip("#").strip().lower()
    return stripped.lower()


def _count_headings(content: str) -> dict:
    """Return a mapping of heading text (lowercased, no #) to line numbers."""
    headings = {}
    for i, line in enumerate(content.splitlines(), start=1):
        stripped = line.strip()
        if stripped.startswith("#"):
            key = _heading_text(stripped)
            headings.setdefault(key, []).append(i)
    return headings


def _heading_matches(pattern_text: str, heading_text: str) -> bool:
    """Check if a heading text matches a pattern text.

    Supports exact match and prefix match (e.g., "phase" matches "phase 1").
    """
    if pattern_text == heading_text:
        return True
    if heading_text.startswith(pattern_text + " "):
        return True
    return False


def _extract_acceptance_criteria(content: str) -> list:
    """Extract acceptance criteria lines (- [ ] ...) with their line numbers."""
    criteria = []
    for i, line in enumerate(content.splitlines(), start=1):
        stripped = line.strip()
        if ACCEPTANCE_CHECKLIST_RE.match(stripped):
            criteria.append((i, stripped))
    return criteria


def check_required_files(files_present: dict) -> list:
    """Verify all required files exist as non-empty regular files."""
    errors = []
    for fname in REQUIRED_FILES:
        status = files_present.get(fname)
        if status is None:
            errors.append(
                f"{fname}: missing required file. "
                f"Create {fname} with substantive content."
            )
        elif status is False:
            errors.append(
                f"{fname}: empty required file. "
                f"Add substantive content to {fname}."
            )
    return errors


def check_required_sections(fname: str, content: str, required: list) -> list:
    """Verify required headings are present (case-insensitive, level-agnostic,
    prefix match for multi-word patterns like "## Phase" matching "## Phase 1")."""
    errors = []
    headings = _count_headings(content)
    for pattern in required:
        pattern_text = _heading_text(pattern)
        found = any(_heading_matches(pattern_text, h) for h in headings)
        if not found:
            errors.append(
                f"{fname}: missing required section '{pattern}'. "
                f"Add '{pattern}' heading."
            )
    return errors


def detect_placeholders(fname: str, content: str) -> list:
    """Flag placeholder tokens outside code fences."""
    errors = []
    lines = content.splitlines()
    in_code_fence = False
    for i, line in enumerate(lines, start=1):
        stripped = line.strip()
        if CODE_FENCE_RE.match(stripped):
            in_code_fence = not in_code_fence
            continue
        if in_code_fence:
            continue
        upper = stripped.upper()
        for token in PLACEHOLDER_TOKENS:
            if token.upper() in upper:
                errors.append(
                    f"{fname}:{i}: placeholder token '{token}' detected. "
                    f"Replace with concrete content or decision."
                )
                break
    return errors


def detect_empty_sections(fname: str, content: str, required: list) -> list:
    """Flag required headings with no substantive body content."""
    errors = []
    headings = _count_headings(content)
    lines = content.splitlines()

    allowed_none_sections = {
        "out of scope",
        "security considerations",
        "dependencies",
    }

    for pattern in required:
        pattern_text = _heading_text(pattern)
        matching = [h for h in headings if _heading_matches(pattern_text, h)]
        if not matching:
            continue

        start_line = headings[matching[0]][0]
        end_line = len(lines) + 1
        current_level = len(lines[start_line - 1]) - len(
            lines[start_line - 1].lstrip("#")
        )
        for j in range(start_line, len(lines)):
            stripped = lines[j].strip()
            if not stripped.startswith("#"):
                continue
            level = len(stripped) - len(stripped.lstrip("#"))
            if level <= current_level:
                end_line = j + 1
                break

        body_lines = lines[start_line:end_line][1:]

        substantive = False
        for bl in body_lines:
            bls = bl.strip()
            if not bls:
                continue
            if bls.startswith("#"):
                continue
            substantive = True
            break

        if not substantive:
            errors.append(
                f"{fname}: empty required section '{pattern}'. "
                f"Add substantive content under '{pattern}'."
            )

    return errors


def check_acceptance_criteria(fname: str, content: str) -> list:
    """Verify acceptance criteria are in checklist format and objective."""
    errors = []
    criteria = _extract_acceptance_criteria(content)

    if not criteria:
        headings = _count_headings(content)
        if "acceptance criteria" in headings:
            errors.append(
                f"{fname}: acceptance criteria not in '- [ ]' checklist format. "
                f"Use '- [ ] <objective behavior>' for each criterion."
            )
        return errors

    for line_no, criterion in criteria:
        criterion_lower = criterion.lower()
        for adj in SUBJECTIVE_ADJECTIVES:
            if adj in criterion_lower:
                has_measurement = bool(re.search(r"\d+(\.\d+)?\s*(ms|s|%|mb|kb|req/s|ops)", criterion_lower))
                if not has_measurement:
                    errors.append(
                        f"{fname}:{line_no}: untestable subjective adjective '{adj}' "
                        f"in criterion. Replace with an objective measurement "
                        f"(e.g., threshold, count, time limit)."
                    )
                    break

    return errors


def detect_contradictions(fname: str, content: str) -> list:
    """Best-effort detection of contradictory acceptance criteria."""
    errors = []
    criteria = _extract_acceptance_criteria(content)
    obligations = []
    must_re = re.compile(r"must\s+(not\s+)?(\w+)", re.IGNORECASE)

    for line_no, criterion in criteria:
        for m in must_re.finditer(criterion):
            polarity = "negative" if m.group(1) else "positive"
            subject = m.group(2).lower()
            obligations.append((line_no, polarity, subject, criterion))

    for i in range(len(obligations)):
        for j in range(i + 1, len(obligations)):
            li, pi, si, ci = obligations[i]
            lj, pj, sj, cj = obligations[j]
            if si == sj and pi != pj:
                errors.append(
                    f"{fname}: contradictory requirements on '{si}': "
                    f"line {li} ('{ci}') vs line {lj} ('{cj}'). "
                    f"Resolve the contradiction."
                )

    return errors


def load_bundle(specs_dir: str, issue_number: int) -> dict:
    """Resolve the issue directory and read its spec bundle files."""
    prefix = f"{issue_number:03d}-"
    issue_dir = None
    specs_path = Path(specs_dir)
    if specs_path.exists():
        for d in sorted(specs_path.iterdir()):
            if d.is_dir() and d.name.startswith(prefix):
                issue_dir = d
                break

    files = {}
    if issue_dir is not None:
        for fname in REQUIRED_FILES:
            path = issue_dir / fname
            if path.exists() and path.is_file():
                if path.stat().st_size == 0:
                    files[fname] = ""  # empty file marker
                else:
                    try:
                        files[fname] = path.read_text()
                    except Exception:
                        files[fname] = None
            else:
                files[fname] = None

    return {
        "issue_dir": issue_dir,
        "files": files,
    }


def validate_bundle(specs_dir: str, issue_number: int) -> tuple:
    """Run all spec-plan quality checks and return (exit_code, errors)."""
    errors = []
    bundle = load_bundle(specs_dir, issue_number)
    files = bundle["files"]

    files_present = {}
    for fname, content in files.items():
        if content is None:
            files_present[fname] = None  # missing
        elif content == "":
            files_present[fname] = False  # empty
        else:
            files_present[fname] = True  # present and non-empty

    errors.extend(check_required_files(files_present))

    for fname in REQUIRED_FILES:
        content = files.get(fname)
        if content is None or content == "":
            continue

        required = REQUIRED_SECTIONS.get(fname, [])
        errors.extend(check_required_sections(fname, content, required))
        errors.extend(detect_placeholders(fname, content))
        errors.extend(detect_empty_sections(fname, content, required))

        if fname == "spec.md":
            errors.extend(check_acceptance_criteria(fname, content))
            errors.extend(detect_contradictions(fname, content))

    return (1 if errors else 0), errors


def run_spec_plan_quality(specs_dir: str, issue_number: int) -> tuple:
    """Entry point: validate bundle and return exit code + errors."""
    exit_code, errors = validate_bundle(specs_dir, issue_number)
    return exit_code, errors


def main(argv=None):
    """CLI entry point."""
    if argv is None:
        argv = sys.argv[1:]

    if len(argv) < 2:
        print(
            "Usage: python scripts/spec_plan_quality.py <specs_dir> <issue_number>",
            file=sys.stderr,
        )
        return 2

    specs_dir = argv[0]
    try:
        issue_number = int(argv[1])
    except ValueError:
        print(
            f"Error: issue_number must be an integer, got '{argv[1]}'",
            file=sys.stderr,
        )
        return 2

    exit_code, errors = run_spec_plan_quality(specs_dir, issue_number)

    if errors:
        print(f"Spec-plan quality checks failed ({CONTRACT_VERSION}):")
        for e in errors:
            print(f"  - {e}")
        return exit_code

    print(f"Spec-plan quality checks passed ({CONTRACT_VERSION})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
