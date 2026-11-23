# Implementation Plan — Issue #129

## Objective

Define objective MVP completion criteria for AiClip: identify minimum end-to-end workflows, classify features as required for MVP / post-MVP / deferred, define test/CI/documentation/accessibility/reliability/operational evidence, and record dependencies, gaps, assumptions, and release blockers. All changes are specification/planning only; follow SDD RED/GREEN/REFACTOR with documentation-validation tests. No production code is implemented.

## Phase 1: RED — Write criteria-validation tests first

### Step 1: Write RED tests validating MVP completion criteria coverage

Create governance-style tests (or add to existing test scenarios) that verify:

- The spec bundle enumerates MVP end-to-end workflows traceable to PRD MVP feature list.
- Each completion criterion maps to at least one objective verification (test, CI, governance, documentation, or operational evidence).
- Social publishing (M10/M11/M12) is classified as deferred for MVP.
- Image Studio remaining slices are classified as deferred for MVP; Slices 1–2 classified as implemented.
- Required test suites (unit, integration, E2E, Playwright, governance) are listed with commands.
- Required CI checks (Backend CI, Frontend CI, E2E CI, governance CI) are listed.
- Documentation checks and operational checks are listed.
- Known gaps and release blockers are documented.
- Final MVP completion is stated as a human-authorized decision.
- No production code is introduced; no `.env`, secret, or credential read/printed.
- All content in English.

These tests must fail (RED) before the spec bundle exists / before criteria are defined, because no objective MVP completion-criteria artifact currently exists.

## Phase 2: GREEN — Author the spec bundle

### Step 2: Create `spec.md`

Document root cause, acceptance criteria, files to modify/create, out of scope, security considerations, UX considerations, test scenarios, and dependencies per AGENTS.md and this issue's issue content.

### Step 3: Create `plan.md`

Document RED/GREEN/REFACTOR phases, conventional commits with `Refs #129`, and verification steps.

### Step 4: Create `test-plan.md`

Document unit tests for MVP completion criteria validation, scenarios mapping to acceptance criteria, application E2E/Playwright N/A justification, and test execution commands.

### Step 5: Re-run criteria-validation tests

All RED tests must now pass (GREEN). No application code is introduced.

## Phase 3: REFACTOR — Improve clarity and consistency without altering meaning

### Step 6: Refactor wording for clarity and consistency

- Ensure each criterion maps to PRD/roadmap/project-state traceability.
- Verify no subjective/untestable adjectives in acceptance criteria.
- Verify no placeholders (TODO/FIXME/TBD).
- Verify English-only content (AGENTS.md §19).
- Re-run criteria-validation tests and governance suite; all must remain green.

## Expected Final State

- `specs/129-governance-mvp-completion-criteria/spec.md` — complete specification.
- `specs/129-governance-mvp-completion-criteria/plan.md` — complete plan.
- `specs/129-governance-mvp-completion-criteria/test-plan.md` — complete test plan.
- `specs/129-governance-mvp-completion-criteria/evidence.md` — written by Builder/Tester during lifecycle (not Planner).
- Governance tests pass; no SDD bundle validation regression (`validate_sdd_bundle` finds no regression for specs/129 bundle).
- No application code introduced; no secrets read or printed.
- Final MVP completion remains a human-authorized decision.

## Commits (Conventional Commits, Refs #129)

- `docs(spec): define objective MVP completion criteria` Refs #129
- `docs(plan): MVP completion criteria RED/GREEN/REFACTOR plan` Refs #129
- `test(governance): add MVP completion criteria validation tests` Refs #129
- `docs(test-plan): MVP completion criteria test scenarios` Refs #129

## Verification

- Criteria-validation tests pass (RED→GREEN→REFACTOR).
- Governance suite: `python -m unittest discover -s tests/governance -p 'test_*.py' -v` passes; `validate_sdd_bundle` finds no regression for specs/129 bundle.
- Each acceptance criterion cross-checked for objective verifiability.
- English-only content confirmed.
- No production code introduced; no unsupported completion/test/security/production-readiness claims.