# Specification — Issue #129: Define Objective MVP Completion Criteria

## Title

Define Objective MVP Completion Criteria

## Description

Define measurable, objectively testable completion criteria for the AiClip MVP so remaining work and release readiness can be assessed against explicit requirements. This planning bundle reviews the PRD, architecture, project-state document, roadmap, and implemented user journeys; classifies features as required for MVP, post-MVP, or deferred; and records test, CI, documentation, accessibility, reliability, and operational evidence needed for completion. No production code or architecture change is introduced; this is specification only.

## Problem

There is no explicit, objectively testable definition of what "MVP complete" means for AiClip. Completed milestones (M0–M9) and planned milestones (M10–M12 social publishing, M8 remaining slices, M14 production hardening) exist, but no single artifact ties them together into release-ready MVP criteria. Without measurable completion criteria, remaining work and release readiness cannot be assessed consistently, and human authorization for release lacks an objective basis.

## Goal

Produce an objective MVP completion-criteria artifact (this spec bundle) that:

1. Identifies the minimum end-to-end workflows required for MVP.
2. Classifies features as required for MVP, post-MVP, or deferred, traceable to PRD and roadmap.
3. Defines test, CI, documentation, accessibility, reliability, and operational evidence needed for completion.
4. Records dependencies, known gaps, assumptions, and release blockers.
5. States explicitly that final MVP completion is a human-authorized decision.
6. Introduces no production code and modifies no application behavior.

## Acceptance Criteria

- [ ] MVP scope is explicit and traceable to existing product requirements (PRD MVP feature list and project-state completed capabilities).
- [ ] Each completion criterion is objectively testable or has a defined review artifact (unit, integration, E2E, Playwright, governance, CI, or documentation evidence).
- [ ] Social publishing and Image Studio extensions are clearly classified as required or deferred for MVP.
- [ ] Required test suites, CI checks, governance, documentation, and operational checks are listed with deterministic verification commands.
- [ ] Gaps and release blockers are documented rather than omitted.
- [ ] Final MVP completion remains a human-authorized decision (not an automated gate).
- [ ] No production code is implemented or modified by this issue.
- [ ] English-only repository content preserved; all linked issues/PRs traceable.

## Files to Modify/Create

### Created by Planner (this issue)

- `specs/129-governance-mvp-completion-criteria/spec.md` — this specification.
- `specs/129-governance-mvp-completion-criteria/plan.md` — implementation plan (RED/GREEN/REFACTOR, conventional commits with Refs #129).
- `specs/129-governance-mvp-completion-criteria/test-plan.md` — test plan and scenarios.

### Created by Builder/Tester (not Planner)

- `specs/129-governance-mvp-completion-criteria/evidence.md` — Builder writes with TDD sections and `Decision:` line.

### Out of Scope for Planner (not modified by Planner)

- `tests/governance/**` — owned by governance test suite; Planner may not modify.
- `scripts/merge_gate.py` — must not be modified; remains authoritative for merge.
- `.github/workflows/**` — CI wiring handled by Orchestrator/Builder outside Planner permissions.
- `.opencode/**` — agent permissions not modified by this issue.
- `docs/project-state.md` — updated by Orchestrator after issue closure (not by this issue).
- `docs/prd.md`, `docs/roadmap.md`, `README.md` — authoritative sources; read only here.
- `AGENTS.md` — normative authority; not modified by this issue.
- Application code (Laravel/React/media worker) — no application behavior changes.
- evidence.md content produced by Builder/Tester during lifecycle.

## Security Considerations

- No secrets, tokens, credentials, or `.env` contents are referenced in this specification.
- Linked issue/PR URLs are public Git metadata, not secrets.
- No security claims are made about unimplemented work.
- Classification of deferred features does not weaken existing security requirements for implemented work.

## UX Considerations

- The MVP completion criteria artifact is a governance document, not a user-facing interface; no UI changes are required.
- Criteria wording must be unambiguous so a Tester can verify pass/fail without subjective judgment.

## Test Scenarios

- C1: MVP scope enumerated and traceable to PRD MVP feature list (in-scope features) and project-state completed capabilities.
- C2: Every completion criterion maps to at least one objective verification (test, CI check, governance check, documentation artifact, or operational check).
- C3: Social publishing (M10/M11/M12) classified as deferred for MVP (requires separate explicit authorization).
- C4: Image Studio remaining slices (real model-backed provider, image deletion, batch generation, editing) classified as deferred for MVP.
- C5: Image Studio Slices 1–2 (foundation + signed download URLs/gallery previews) classified as implemented; required test suites listed.
- C6: Required unit, integration, E2E, Playwright, governance, CI, documentation, and operational checks enumerated with commands.
- C7: Known gaps (email verification, no project edit endpoint, real transcription/model download, real scene detection, signed clip-review video E2E coverage deferred) documented.
- C8: Release blockers (M14 Production Hardening merge/close, PR #141 pending) documented.
- C9: Final MVP completion stated as human-authorized decision; no automated merge-to-release without human authorization.
- C10: No production code introduced; no `.env`, secret, or credential read/printed.
- C11: All content in English; no Portuguese artifacts (AGENTS.md §19).

## Dependencies

- `docs/prd.md` — MVP Feature List (in-scope / out-of-scope table), Success Criteria, Non-Functional Requirements.
- `docs/project-state.md` — completed capabilities (M0–M9), known limitations, current milestone, next architectural goal.
- `docs/roadmap.md` — milestones M10/M11/M12 publishing planned/not implemented; M14 Production Hardening.
- `README.md` — development status table, future work / next architectural goals.
- Governance validators in `tests/governance/validators.py` (SDD bundle validation `validate_sdd_bundle`).
- Existing governance workflow `.github/workflows/governance.yml`.
- Branch `@carlosegoulart/129/feat/governance-mvp-completion-criteria` already created by Orchestrator.
- Directory `specs/129-governance-mvp-completion-criteria/` already created by Orchestrator.
- AGENTS.md §19 (English-only repository content).