# Test Plan — Issue #5

## Overview

Test scenarios for validating documentation inconsistency corrections. This is documentation-only work, so tests are structural and consistency checks.

## Test Scenarios

### T1: Architecture Terminology Validation

Verify `docs/architecture.md` no longer describes itself as microservices.

**Checklist:**
- [ ] "microservices" does not appear in `docs/architecture.md`
- [ ] Architecture description reflects modular monolith with worker boundary

### T2: Technology Version Validation

Verify concrete versions are used consistently.

**Checklist:**
- [ ] `docs/architecture.md` uses React 19 (not 18+)
- [ ] `docs/architecture.md` uses Laravel 11 (not 10+)
- [ ] `docs/architecture.md` uses PHP 8.3 (not 8.2+)
- [ ] `docs/architecture.md` uses Python 3.12 (not 3.11+)
- [ ] `README.md` matches architecture versions

### T3: API Strategy Validation

Verify REST is the single documented strategy.

**Checklist:**
- [ ] "GraphQL" does not appear in `docs/architecture.md`
- [ ] REST JSON API with /api/v1 is documented
- [ ] Endpoint examples use /api/v1 prefix

### T4: Authentication Validation

Verify Sanctum SPA model is documented.

**Checklist:**
- [ ] "HTTP-only session cookie" appears in authentication section
- [ ] "CSRF protection" appears
- [ ] User auth is distinguished from social OAuth
- [ ] "15-minute access tokens" does not appear
- [ ] "refresh tokens stored encrypted" does not appear for first-party auth

### T5: Image Provider Validation

Verify open-weight providers are listed.

**Checklist:**
- [ ] "OpenAIImageProvider" does not appear as primary
- [ ] "FluxImageProvider" or "SdxlImageProvider" appears
- [ ] "FakeImageProvider" is listed for CI

### T6: Development AI vs Product AI Validation

Verify distinction is documented.

**Checklist:**
- [ ] Development AI section or note exists
- [ ] Product AI section or note exists
- [ ] OpenCode models are not described as product AI

### T7: Video AI Strategy Validation

Verify clipping does not require generative video.

**Checklist:**
- [ ] "does NOT require generative video models" or equivalent appears
- [ ] Pipeline describes FFprobe → transcription → detection → ranking → reframing → render

### T8: Clip Editor Scope Validation

Verify basic trimming is allowed.

**Checklist:**
- [ ] "trimming" is NOT in the explicit exclusions list
- [ ] Advanced exclusions mention multi-track timeline, compositing, effects, keyframe animation

### T9: Social Media Manager Validation

Verify persona does not mention performance metrics.

**Checklist:**
- [ ] "performance metrics" does not appear in Social Media Manager persona
- [ ] Use case mentions "Track publishing status across platforms"

### T10: One-Click Publishing Validation

Verify semantics are documented.

**Checklist:**
- [ ] "one-click" or "one explicit confirmation" appears
- [ ] "publication batch" or equivalent appears
- [ ] "independent status" per platform is documented
- [ ] "idempotent" or equivalent is documented

### T11: Performance Requirements Validation

Verify targets are reclassified.

**Checklist:**
- [ ] "Performance Benchmarks" or "Benchmarks" section exists
- [ ] "subject to measurement" or equivalent appears
- [ ] Hard targets (10 GB, 10 minutes, 30 seconds) are in benchmarks section

### T12: Security Review Validation

Verify third-party review is removed.

**Checklist:**
- [ ] "Third-party security review" does not appear
- [ ] "Internal security review" appears

### T13: Issue Number References Validation

Verify milestone references replace issue numbers.

**Checklist:**
- [ ] "Issue #2 (Application Scaffolding)" does not appear
- [ ] "M1 — Application Foundation" or equivalent appears
- [ ] No predicted future issue numbers in `docs/architecture.md` or `docs/prd.md`

### T14: Queue Boundary Validation

Verify async boundary is clarified.

**Checklist:**
- [ ] Python worker consumes using compatible client (rq, boto3)
- [ ] "Laravel-native serialized jobs" does not appear as worker method

### T15: Worker Database Access Validation

Verify Laravel owns persistence.

**Checklist:**
- [ ] "Laravel owns application persistence" or equivalent appears
- [ ] Worker results cross "explicit application boundary"
- [ ] Worker does not "directly write to PostgreSQL"

### T16: Roadmap Current Milestone Validation

Verify roadmap reflects current state.

**Checklist:**
- [ ] Current milestone is not "M0"
- [ ] References issue #3 and #5 as completed

### T17: Cross-Document Consistency Validation

Verify no contradictions between documents.

**Checklist:**
- [ ] PRD features match architecture services
- [ ] Architecture matches roadmap milestones
- [ ] ADR decisions match architecture document
- [ ] Project state reflects actual repository state

### T18: Language Validation

Verify all documentation is in English.

**Checklist:**
- [ ] No Portuguese text in any modified file

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue.

## Test Execution

Run governance tests to verify no regressions:

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Document reason: This issue only modifies documentation files; no application behavior exists to test with E2E or Playwright.
