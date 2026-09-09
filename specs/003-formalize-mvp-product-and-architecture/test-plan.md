# Test Plan — Issue #3

## Overview

Test scenarios for validating the documentation formalization work. This is documentation-only, so tests are structural and consistency checks, not application tests.

## Test Scenarios

### T1: PRD Structural Validation

Verify `docs/prd.md` contains all required sections.

**Checklist:**
- [ ] Product Vision section exists
- [ ] Target Users section exists with at least 3 personas
- [ ] Core Workflows section exists with at least 5 workflows
- [ ] MVP Feature List section exists with in/out boundaries
- [ ] Success Criteria section exists
- [ ] Non-Functional Requirements section exists
- [ ] Explicit Exclusions section exists

### T2: Architecture Structural Validation

Verify `docs/architecture.md` contains all required sections.

**Checklist:**
- [ ] System Overview section exists
- [ ] Service Decomposition section exists with at least 4 boundaries
- [ ] Data Flow section exists
- [ ] API Boundary section exists
- [ ] Database Entity Overview section exists (entities and relationships, not DDL)
- [ ] Provider Abstraction Contracts section exists with at least 4 providers
- [ ] Security Architecture section exists
- [ ] Deployment Topology section exists

### T3: ADR Validation

Verify `docs/adr/0002-mvp-product-and-architecture.md` exists and is complete.

**Checklist:**
- [ ] File exists at `docs/adr/0002-mvp-product-and-architecture.md`
- [ ] Title is present
- [ ] Date is present
- [ ] Status is "Accepted"
- [ ] Context section exists
- [ ] Decision section exists
- [ ] Consequences section exists

### T4: Project State Validation

Verify `docs/project-state.md` is updated.

**Checklist:**
- [ ] Current Architecture section reflects documentation state
- [ ] Completed Capabilities section mentions issue #3
- [ ] Current Milestone section is updated
- [ ] Next Architectural Goal section is updated

### T5: README Link Validation

Verify `README.md` links are valid.

**Checklist:**
- [ ] Link to `docs/prd.md` exists and is valid
- [ ] Link to `docs/architecture.md` exists and is valid
- [ ] Link to `docs/adr/0002-mvp-product-and-architecture.md` exists or is referenced
- [ ] Current state section is accurate

### T6: No Application Code Validation

Verify no application code was introduced.

**Checklist:**
- [ ] `git diff --name-only` shows only documentation files
- [ ] No new PHP files
- [ ] No new TypeScript/JavaScript files
- [ ] No new Python files
- [ ] No new SQL files
- [ ] No new Docker/CI config files

### T7: Consistency Validation

Verify documents agree with each other.

**Checklist:**
- [ ] PRD features are covered by architecture services
- [ ] Architecture services align with roadmap milestones
- [ ] ADR decisions match architecture document
- [ ] Project state reflects actual repository state
- [ ] No contradictions between any two documents

### T8: Language Validation

Verify all documentation is in English.

**Checklist:**
- [ ] `docs/prd.md` is in English
- [ ] `docs/architecture.md` is in English
- [ ] `docs/adr/0002-mvp-product-and-architecture.md` is in English
- [ ] `docs/project-state.md` is in English
- [ ] `README.md` is in English
- [ ] No Portuguese content in any repository artifact

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue.

## Test Execution

Run governance tests to verify no regressions:

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Document reason: This issue only modifies documentation files; no application behavior exists to test with E2E or Playwright.
