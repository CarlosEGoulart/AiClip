# Specification — Issue #127: Validate Spec and Plan Quality

## Title

Validate Spec and Plan Quality

## Description

Add automated quality checks for per-issue specification bundles so implementation begins only when required planning artifacts are present and sufficiently actionable. The validator enforces a versioned, objective quality contract over `spec.md`, `plan.md`, and `test-plan.md`, detecting placeholders, empty sections, missing required sections, acceptance criteria that are not objectively verifiable, and contradictory requirements, without rewriting specs or making subjective implementation decisions.

## Problem

Every governed issue requires a planning bundle (`spec.md`, `plan.md`, `test-plan.md`) before implementation begins, but there is no objective quality gate ensuring those bundles are complete, actionable, and internally consistent. Bundles can contain:

- Empty sections with headings but no substantive content.
- Placeholder text (TODO, FIXME, TBD, lorem ipsum) that hides missing decisions.
- Acceptance criteria written subjectively ("looks good", "fast enough") that cannot be verified by tests.
- Contradictory requirements within the same bundle.
- Missing required sections (Out of Scope, Security Considerations, Test Scenarios, Dependencies).

As a result, implementation can start against incomplete or ambiguous specs, causing rework, blocked Builder/Tester work, and governance debt.

## Goal

Establish a versioned, objective quality contract for spec bundles and a deterministic validator (`scripts/spec_plan_quality.py`) that:

1. Requires `spec.md`, `plan.md`, and `test-plan.md` to exist as non-empty regular files for each governed issue directory.
2. Requires a defined set of sections in each file.
3. Requires acceptance criteria in checklist format (`- [ ]`) that are objectively verifiable.
4. Detects placeholder content (TODO, FIXME, TBD, lorem ipsum, etc.).
5. Detects empty sections (heading with no substantive body).
6. Detects contradictory requirements where practical (same subject, opposite obligation).
7. Returns non-zero with concise, actionable diagnostics naming the exact failing file and section.
8. Modifies no files automatically (read-only validation).
9. Is integrated into preflight/governance CI without duplicating unrelated policy.
10. Provides fixtures for valid and invalid bundles and documents the remediation workflow.

## Quality Contract (Versioned)

### Contract version

`spec-plan-quality v1.0`

The contract version is documented in this spec and referenced by the validator output. Future changes to required sections or rules bump the version and are treated as separately scoped changes.

### Required planning files

For each governed issue directory `specs/{NNN-slug}/`:

| File | Required | Produced by |
|------|----------|-------------|
| `spec.md` | Yes (non-empty regular file) | Planner |
| `plan.md` | Yes (non-empty regular file) | Planner |
| `test-plan.md` | Yes (non-empty regular file) | Planner |
| `evidence.md` | No (not required at spec-plan quality time; produced by Builder/Tester during lifecycle) | Builder/Tester |

`evidence.md` is intentionally out of scope for this validator; it is governed by issue #126 (evidence freshness) and issue #125 (spec bundle completeness).

### Required sections — spec.md

| # | Section | Heading (case-insensitive) | Meaning |
|---|---------|---------------------------|---------|
| S1 | Title | `# Title` | Clear and concise title of the work. |
| S2 | Description | `## Description` | Goal and why it is needed. |
| S3 | Acceptance Criteria | `## Acceptance Criteria` | Checklist of objectively verifiable behaviors. |
| S4 | Out of Scope | `## Out of Scope` | Explicitly out-of-scope work (may state "None"). |
| S5 | Security Considerations | `## Security Considerations` | Security/privacy implications (may state "None"). |
| S6 | Test Scenarios | `## Test Scenarios` | Traceable test scenarios mapping to acceptance criteria. |
| S7 | Dependencies | `## Dependencies` | Dependencies and human-gated operations (may state "None"). |

Recommended but not enforced by this contract: `## Problem`, `## Goal`, `## Files to Modify/Create`, `## UX Considerations`.

### Required sections — plan.md

| # | Section | Heading (case-insensitive) | Meaning |
|---|---------|---------------------------|---------|
| P1 | Objective | `## Objective` | What the plan delivers. |
| P2 | Phase-based steps | `## Phase` (one or more) | RED/GREEN/REFACTOR phases with steps. |
| P3 | Commits | `## Commits` | Conventional Commits with `Refs #<issue>`. |
| P4 | Verification | `## Verification` | How correctness is verified. |

### Required sections — test-plan.md

| # | Section | Heading (case-insensitive) | Meaning |
|---|---------|---------------------------|---------|
| T1 | Overview | `## Overview` | What the tests cover and why. |
| T2 | Test Scenarios | `## Test Scenarios` | Enumerated scenarios with expected outcomes. |
| T3 | Application E2E / Playwright | `## Application E2E / Playwright` | N/A justification or Playwright coverage. |
| T4 | Test Execution | `## Test Execution` | Commands to run the tests. |

### Acceptance criteria rules

- Each acceptance criterion must use checklist format: `- [ ] <behavior>`.
- Each criterion must be objectively verifiable: verifiable by unit, integration, E2E, or Playwright test without subjective judgment.
- Criteria must not contain subjective adjectives that cannot be tested (e.g., "good", "better", "fast", "nice", "smooth", "intuitive") unless paired with an objective measurement.
- Criteria must not be placeholders (TODO, FIXME, TBD, "to be decided").

### Placeholder detection (read-only, fail closed)

The validator flags any of the following tokens (case-insensitive) appearing in spec.md, plan.md, or test-plan.md body content outside code fences:

- `TODO`, `FIXME`, `TBD`, `XXX`, `PLACEHOLDER`, `lorem ipsum`, `to be decided`, `tbd`, `todo`, `fixme`

A match produces a diagnostic naming the file, section, and line.

### Empty section detection

A required section whose heading is present but whose body contains no substantive content (only whitespace, a single dash, or a placeholder token) is flagged as empty. "None" is accepted as substantive for sections that explicitly allow it (Out of Scope, Security Considerations, Dependencies).

### Contradictory requirement detection (best-effort)

Within acceptance criteria, the validator flags pairs of criteria that address the same subject with opposite obligations (e.g., one criterion says "must X" and another says "must not X" on the same identifiable subject). This is best-effort: exact lexical opposites on the same line-subject are flagged; deeper semantic contradictions require human review and are documented in the remediation workflow.

### Non-objective criterion detection

Acceptance criteria containing untestable subjective adjectives (good, better, fast, nice, smooth, intuitive, clean, polished, robust, stable, scalable, performant without a threshold) are flagged with a diagnostic suggesting an objective replacement.

### What the validator does NOT do

- Does not rewrite, edit, or modify any spec file.
- Does not make subjective implementation decisions.
- Does not change agent permissions or governance policy.
- Does not replace human review for semantic contradictions or domain correctness.
- Does not validate evidence.md (covered by issue #126).
- Does not validate branch names or commit messages (covered by issue #125 and existing validators).

## Acceptance Criteria

- [ ] The quality contract is explicit, versioned (`spec-plan-quality v1.0`), and documented in this spec.
- [ ] Required planning files (`spec.md`, `plan.md`, `test-plan.md`) are checked deterministically for existence and non-emptiness.
- [ ] Required sections in each file are checked deterministically (case-insensitive headings).
- [ ] Placeholder content (TODO, FIXME, TBD, etc.) fails validation with actionable diagnostics naming file, section, and line.
- [ ] Empty required sections fail validation with actionable diagnostics.
- [ ] Acceptance criteria not in `- [ ]` checklist format fail validation.
- [ ] Acceptance criteria containing untestable subjective adjectives fail validation.
- [ ] Contradictory requirements (best-effort lexical opposites) fail validation.
- [ ] Valid existing spec bundles pass validation; any migration of legacy bundles is a separately scoped change documented as a legacy exception.
- [ ] Unit/fixture tests cover compliant, incomplete, and malformed bundles.
- [ ] The validator does not silently rewrite specs or make subjective implementation decisions.
- [ ] The validator integrates into preflight/governance CI without duplicating unrelated policy.
- [ ] Fixtures for valid and invalid examples are provided.
- [ ] The remediation workflow is documented.
- [ ] Agent permissions and existing governance requirements are not weakened.

## Files to Modify/Create

### New files (Planner bundle, this issue)

- `specs/127-governance-spec-plan-quality/spec.md` — this specification.
- `specs/127-governance-spec-plan-quality/plan.md` — implementation plan (RED/GREEN/REFACTOR).
- `specs/127-governance-spec-plan-quality/test-plan.md` — test plan and scenarios.
- `specs/127-governance-spec-plan-quality/fixtures/valid/spec.md` — compliant spec.md fixture.
- `specs/127-governance-spec-plan-quality/fixtures/valid/plan.md` — compliant plan.md fixture.
- `specs/127-governance-spec-plan-quality/fixtures/valid/test-plan.md` — compliant test-plan.md fixture.
- `specs/127-governance-spec-plan-quality/fixtures/invalid/missing-sections/spec.md` — spec missing required sections.
- `specs/127-governance-spec-plan-quality/fixtures/invalid/placeholder/spec.md` — spec containing TODO/FIXME placeholders.
- `specs/127-governance-spec-plan-quality/fixtures/invalid/empty-section/plan.md` — plan with an empty required section.
- `specs/127-governance-spec-plan-quality/fixtures/invalid/subjective-criteria/test-plan.md` — test-plan with non-objective criteria.
- `specs/127-governance-spec-plan-quality/fixtures/invalid/contradictory/spec.md` — spec with contradictory acceptance criteria.

### Created by Builder/Tester (not Planner)

- `scripts/spec_plan_quality.py` — deterministic spec-plan quality validator (pure functions, no side effects, no network, no secrets).
- `tests/governance/test_spec_plan_quality.py` — unit tests for spec-plan quality checks.
- `specs/127-governance-spec-plan-quality/evidence.md` — Builder writes with TDD sections and `Decision:` line.

### Out of Scope for Planner (not modified by Planner)

- `tests/governance/**` — owned by governance test suite; Planner may not modify.
- `scripts/merge_gate.py` — must not be weakened or modified; remains authoritative for merge.
- `scripts/evidence_freshness.py` — not modified; separate concern (issue #126).
- `scripts/preflight.py` — not modified; separate concern (issue #125). Integration pattern may be referenced.
- `.github/workflows/governance.yml` — CI wiring handled by Orchestrator/Builder outside Planner permissions.
- `.opencode/**` — agent permissions not modified by this issue.
- `docs/project-state.md` — updated by Orchestrator after issue closure.
- Application code (Laravel/React/media worker) — no application behavior changes.
- Legacy bundle migration — handled as a separately scoped change; documented as legacy exceptions.

## Security Considerations

- The validator reads only spec bundle files and fixture files; it never reads `.env`, secrets, tokens, or credentials.
- No secrets are printed in diagnostics (diagnostics reference file/section/line only).
- Commit SHAs and PR URLs are not part of this validator.
- CI remains the final authority; local validation is advisory.
- The validator modifies no files (read-only).

## UX Considerations

- Error messages must name the exact failing file, section, and, where applicable, line number.
- Failure output must be concise and actionable for the contributor (e.g., "spec.md: missing required section 'Acceptance Criteria'; add `## Acceptance Criteria` with `- [ ]` checklist items").
- No UI changes are required; this is governance/tooling only.

## Test Scenarios

- Q1: Valid compliant bundle (spec.md, plan.md, test-plan.md all present with required sections and objective criteria) → pass, no errors.
- Q2: Missing spec.md → fail, names missing file.
- Q3: Missing plan.md → fail, names missing file.
- Q4: Missing test-plan.md → fail, names missing file.
- Q5: Empty spec.md file → fail, names file and "empty".
- Q6: spec.md missing required section (e.g., Acceptance Criteria) → fail, names missing section.
- Q7: plan.md missing required section (e.g., Commits) → fail, names missing section.
- Q8: test-plan.md missing required section (e.g., Test Scenarios) → fail, names missing section.
- Q9: Placeholder content (TODO/FIXME/TBD) in spec.md → fail, names file, section, line.
- Q10: Empty required section (heading with no body) → fail, names file and section.
- Q11: Acceptance criteria not in `- [ ]` checklist format → fail, names file and criterion.
- Q12: Acceptance criteria with untestable subjective adjectives → fail, names file and criterion.
- Q13: Contradictory acceptance criteria (must X / must not X on same subject) → fail, names file and criteria.
- Q14: Valid existing spec bundles pass (no false positives on compliant bundles) → pass.
- Q15: Validator does not modify any spec file → read-only confirmed.
- Q16: No secrets read or printed → confirmed.
- Q17: Exit code 0 on all-pass; non-zero on any violation.
- Q18: Existing governance tests continue to pass (no regression).
- Q19: Contract version `spec-plan-quality v1.0` is reported in validator output.

## Dependencies

- Python 3.12 (existing CI setup).
- Existing governance validators in `tests/governance/validators.py` (pattern reference for deterministic pure functions).
- Existing `scripts/preflight.py` (issue #125) — pattern reference for deterministic CLI tools.
- Existing `scripts/evidence_freshness.py` (issue #126) — pattern reference for deterministic validators with field-named diagnostics.
- Existing `.github/workflows/governance.yml` (CI runs governance tests).
- PyYAML 6.0.3 (already present in `tests/governance/requirements.txt`) — optional, for structured fixture parsing if needed.
- `specs/125-governance-preflight-hooks/`, `specs/126-governance-evidence-freshness/` — existing bundles as structural references.
