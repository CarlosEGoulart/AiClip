# Specification — Issue #126: Governance Evidence Freshness and Traceability

## Title

Enforce Evidence Freshness and Traceability

## Description

Make issue evidence verifiable, current, and traceable to the exact commit and checks it claims to describe. Evidence currently records a Tester decision but does not bind that decision to a commit SHA, PR URL, CI check states, or merge/closeout state, so evidence can become stale, contradictory, or unverifiable without detection.

## Problem

Evidence files (`specs/{NNN-slug}/evidence.md`) contain a `Decision: APPROVE` or `Decision: REJECT` line but:

- Do not record the commit SHA the decision was taken against.
- Do not record the PR URL the decision applies to.
- Do not record CI check states that were observed at decision time.
- Do not record merge/closeout state when applicable.
- Have no freshness rule that invalidates evidence after a relevant code or test change.
- Have no standardized vocabulary for evidence status beyond the two decision words.

This means stale evidence can survive a code change, conflicting decisions can coexist undetected, and the merge gate cannot verify that a decision reflects the state being merged.

## Goal

Establish a normative evidence contract that makes every evidence field explicit, binds evidence to the exact commit/PR state being evaluated, defines freshness rules that fail closed on staleness, standardizes decision-line validation, and provides regression coverage for missing, stale, contradictory, malformed, and fabricated-looking evidence markers.

## Evidence Contract (Normative)

### Required evidence fields

Every evidence.md for a governed issue must contain:

| Field | Format | Meaning |
|-------|--------|---------|
| Decision | `Decision: APPROVE` or `Decision: REJECT` | Official Tester decision line. |
| Commit SHA | `Commit: <40-hex-sha>` | Full commit SHA the decision was taken against. |
| PR URL | `PR: <https://github.com/<owner>/<repo>/pull/<n>>` | Pull Request URL the decision applies to. |
| CI checks | `CI: <check-name>: <state>` | One line per observed CI check state at decision time. |
| Evidence timestamp | `Evidence-At: <ISO-8601 UTC>` | When the evidence record was created. |
| Merge/closeout state | `Merge-State: <open|merged|closed>` | State of the issue/PR at decision time (when applicable). |

### Decision-line format (standardized vocabulary)

- Valid decision line: `Decision: APPROVE` or `Decision: REJECT` (case-insensitive word, optional surrounding `**` markup as already accepted by `merge_gate.py`).
- Exactly one official decision line is allowed in an evidence file.
- Multiple decision lines (any mix of APPROVE/REJECT) are contradictory and fail closed.
- No synthesized or inferred decision: a `Decision:` line must be present and must not be fabricated by automation.

### Freshness rules

Evidence is stale (invalid) when any of the following holds:

- The commit SHA recorded in evidence does not match the head commit SHA of the branch/PR being evaluated.
- The PR URL recorded in evidence does not match the PR being evaluated.
- A relevant code or test change has occurred after the evidence timestamp and the evidence does not reference the new commit.
- Any CI check listed in evidence was not actually run, or its recorded state differs from the observed state.
- Evidence references checks that were not run (fabricated-looking markers).
- Evidence is missing required fields (Decision, Commit, PR, CI, Evidence-At).

Stale or missing evidence fails closed with a clear reason naming the failing field.

## Acceptance Criteria

- [ ] Evidence requirements and freshness rules are documented in one normative contract (this spec).
- [ ] Validation ties evidence to the exact commit/PR state being evaluated (Commit SHA + PR URL + CI states).
- [ ] Missing or stale evidence fails closed with a clear reason naming the failing field.
- [ ] Conflicting or multiple official decisions are rejected; only a valid official decision line is accepted.
- [ ] Tests cover stale commits, missing checks, conflicting decisions, and valid evidence.
- [ ] No test result, review approval, or CI status is synthesized or inferred without source evidence.
- [ ] Existing governance and merge-gate behavior remain consistent (no weakening of the merge gate).

## Files to Modify/Create

### New files (Planner bundle, this issue)

- `specs/126-governance-evidence-freshness/spec.md` — this specification.
- `specs/126-governance-evidence-freshness/plan.md` — implementation plan (RED/GREEN/REFACTOR).
- `specs/126-governance-evidence-freshness/test-plan.md` — test plan and scenarios.

### Created by Builder/Tester (not Planner)

- `scripts/evidence_freshness.py` — deterministic evidence freshness validator (pure functions, no network, no secrets).
- `tests/governance/test_evidence_freshness.py` — unit tests for evidence freshness checks.
- `specs/126-governance-evidence-freshness/evidence.md` — Builder writes with TDD sections and `Decision:` line.

### Out of Scope for Planner (not modified by Planner)

- `tests/governance/**` — owned by governance test suite; Planner may not modify.
- `scripts/merge_gate.py` — must not be weakened or modified; remains authoritative for merge.
- `.github/workflows/governance.yml` — CI wiring handled by Orchestrator/Builder outside Planner permissions.
- `.opencode/**` — agent permissions not modified by this issue.
- `docs/project-state.md` — updated by Orchestrator after issue closure.
- Application code (Laravel/React/media worker) — no application behavior changes.

## Security Considerations

- Evidence records contain no secrets, tokens, credentials, or `.env` contents.
- Commit SHAs and PR URLs are public Git metadata, not secrets.
- The validator reads only evidence files and git metadata; it never reads `.env` or prints credentials.
- CI remains the final authority; local validation is advisory.

## UX Considerations

- Error messages must name the exact failing evidence field (e.g., "Commit mismatch", "Missing Decision", "CI check not run: Backend CI / tests").
- Failure output must be concise and actionable for the contributor.
- No UI changes are required; this is governance/tooling only.

## Test Scenarios

- E1: Valid evidence (Decision + Commit + PR + CI + Evidence-At + Merge-State, all consistent) → pass.
- E2: Missing Decision line → fail closed, names missing field.
- E3: Stale commit SHA (evidence Commit != branch head SHA) → fail closed, names commit field.
- E4: Stale PR URL (evidence PR != evaluated PR) → fail closed, names PR field.
- E5: Missing CI check listed in REQUIRED_CHECKS → fail closed, names missing check.
- E6: CI check state mismatch (evidence says pass, observed fail/pending) → fail closed.
- E7: CI check listed that was not run (fabricated marker) → fail closed.
- E8: Multiple decision lines (APPROVE + REJECT) → fail closed, names conflict.
- E9: Malformed decision line (e.g., `Decision: MAYBE`) → fail closed.
- E10: Empty evidence content → fail closed.
- E11: Missing Commit field → fail closed.
- E12: Missing PR field → fail closed.
- E13: Missing Evidence-At timestamp → fail closed.
- E14: No synthesized approval without source Decision line → fail closed.
- E15: Existing governance tests continue to pass (no regression).

## Dependencies

- Python 3.12 (existing CI setup).
- Existing governance validators in `tests/governance/validators.py` (decision-line patterns).
- Existing `scripts/merge_gate.py` (DECISION_RE pattern, not modified).
- Existing `.github/workflows/governance.yml` (CI runs governance tests).
- Existing `scripts/preflight.py` (issue #125) — pattern reference for deterministic CLI tools.
