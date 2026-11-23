# Specification — Issue #128: Governance Roadmap and README Sync

## Title

Update Roadmap and README to Match the Current Project State

## Description

Reconcile the public-facing project overview (`README.md`) and milestone plan (`docs/roadmap.md`) against the authoritative state in `docs/project-state.md`, merged PRs, closed issues, and current repository capabilities. Stale milestone status descriptions must be corrected, including M7 Slice 2 and M8 Slice 2. Implemented capabilities must be distinguished from future work and from proposals not yet authorized. Next architectural goals and dependencies for social connections, platform publishing, Image Studio extensions, and production readiness must be documented. All content must remain in English and link relevant issues/PRs as traceable evidence. No unsupported completion, test, security, or production-readiness claims may remain.

## Problem

`README.md` and `docs/roadmap.md` are out of sync with `docs/project-state.md` (the authoritative record):

- README table omits M7 Slice 2 (Issue #110, PR #111 — merged via `scripts/merge_gate.py`).
- README table does not list M14 Production Hardening (Issue #123, PR #141 CI green/mergeable, not yet merged/closed).
- README has no future-work / next-architectural-goals section, so remaining M8/M7/M6 slices and M10/M11/M12 publishing appear ambiguous.
- roadmap.md lists M9 Social Connection Framework under Planned Milestones (not marked completed, though Issue #118 closed via PR #130).
- roadmap.md M7 section omits Slice 2 (Issue #110, PR #111).
- roadmap.md Planned Milestones table has duplicate M14 entries (YouTube Publishing / Instagram Publishing / TikTok Publishing / Unified One-Click Publishing / Production Hardening).
- No section distinguishes authorized future work from unauthored proposals, risking claims that future items are implemented.

## Goal

Update `README.md` and `docs/roadmap.md` so they agree with `docs/project-state.md` and merged records:

1. Mark M7 Slice 2 completed with Issue #110 / PR #111 evidence.
2. Mark M8 Slices 1–2 completed with Issue #113/#115, PR #114/#116 evidence.
3. Mark M9 Social Connection Framework completed with Issue #118 / PR #130 evidence.
4. List M14 Production Hardening (Issue #123, PR #141) as CI green/mergeable, not yet merged/closed.
5. Document next architectural goals: remaining M8/M7/M6 slices and M10/M11/M12 social publishing, each requiring separate explicit authorization.
6. Link each completed milestone to its issue/PR evidence where available.
7. Clearly mark future work as planned/not implemented; no unsupported production-readiness claims.
8. Preserve English-only repository content.

## Acceptance Criteria

- [ ] README.md milestone table agrees with docs/project-state.md (M7 Slice 2, M8 Slices 1–2, M9 completed; M14 listed as mergeable-not-closed).
- [ ] Each completed milestone in README and roadmap links to its issue/PR evidence where available.
- [ ] Future work is clearly marked as planned/not implemented (no implication of implementation).
- [ ] No unsupported completion, test, security, or production-readiness claims remain.
- [ ] README and roadmap agree with authoritative project-state records.
- [ ] Documentation checks and governance tests pass (no SDD bundle validation regression).
- [ ] Repository language policy (English only) preserved; all linked issues/PRs are traceable.

## Files to Modify/Create

### Created by Planner (this issue)

- `specs/128-governance-roadmap-readme-sync/spec.md` — this specification.
- `specs/128-governance-roadmap-readme-sync/plan.md` — implementation plan (RED/GREEN/REFACTOR).
- `specs/128-governance-roadmap-readme-sync/test-plan.md` — test plan and scenarios.

### Created by Builder/Tester (not Planner)

- README.md — milestone table, future-work section, evidence links.
- docs/roadmap.md — M7 Slice 2, M9 completed, Planned Milestones table corrected.
- `specs/128-governance-roadmap-readme-sync/evidence.md` — Builder writes with TDD sections and Decision line.

### Out of Scope for Planner (not modified by Planner)

- `tests/governance/**` — owned by governance test suite; Planner may not modify.
- `scripts/merge_gate.py` — must not be modified; remains authoritative for merge.
- `.github/workflows/**` — CI wiring handled by Orchestrator/Builder outside Planner permissions.
- `.opencode/**` — agent permissions not modified by this issue.
- `docs/project-state.md` — authoritative source; updated by Orchestrator after issue closure (not by this issue).
- `AGENTS.md` — normative authority; not modified by this issue.
- Application code (Laravel/React/media worker) — no application behavior changes.
- evidence.md content produced by Builder/Tester during lifecycle.

## Security Considerations

- No secrets, tokens, credentials, or `.env` contents are referenced in README/roadmap updates.
- Linked issue/PR URLs are public Git metadata, not secrets.
- No security claims are made about unimplemented work.
- Documentation-only change; no attack surface introduced.

## UX Considerations

- README remains a clear, accurate public-facing project overview.
- Roadmap milestone table is scannable and distinguishes completed vs. planned work.
- No UI changes are required; this is documentation only.

## Test Scenarios

- D1: README milestone table matches docs/project-state.md (M7 Slice 2 completed #110/#111; M8 Slices 1–2 completed #113/#114, #115/#116; M9 completed #118/#130; M14 listed as CI green/mergeable not closed).
- D2: roadmap.md M7 section includes Slice 2 with Issue #110 / PR #111.
- D3: roadmap.md M9 marked completed with Issue #118 / PR #130 (not under Planned Milestones as pending).
- D4: roadmap.md Planned Milestones table has no duplicate M14 entries; M10/M11/M12 publishing marked planned/not implemented.
- D5: Future work explicitly labeled planned/not implemented (no implied implementation).
- D6: No production-readiness or release claims for future/unimplemented items.
- D7: All content in English; no Portuguese artifacts.
- D8: Governance test suite passes (SDD bundle validation `validate_sdd_bundle` finds no regression for specs/128 bundle).
- D9: Each completed milestone links to traceable issue/PR evidence where available.

## Dependencies

- docs/project-state.md — authoritative source; must be read before editing.
- Merged PR records: #107 (M7 S1), #111 (M7 S2), #114 (M8 S1), #116 (M8 S2), #130 (M9), #141 (M14 CI green).
- Closed issue records: #110 (M7 S2), #113 (M8 S1), #115 (M8 S2), #118 (M9), #123 (M14).
- Existing governance validators in `tests/governance/validators.py` (SDD bundle validation).
- Existing `.github/workflows/governance.yml` (CI runs governance tests).
- AGENTS.md §19 (English-only repository content).
- Branch `@carlosegoulart/128/feat/governance-roadmap-readme-sync` already created by Orchestrator.
- Directory `specs/128-governance-roadmap-readme-sync/` already created by Orchestrator.
