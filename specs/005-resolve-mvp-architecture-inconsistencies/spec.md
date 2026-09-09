# Specification — Issue #5: Resolve MVP Architecture Inconsistencies

## Title

Resolve MVP Architecture Inconsistencies Before Scaffolding

## Description

Issue #3 formalized MVP product definition and system architecture documentation. During review, several technical inconsistencies and premature decisions were identified that could mislead future Builder agents and create divergent assumptions. This issue corrects them before application scaffolding begins.

## Problem

Without resolving these inconsistencies, future Builder agents will make conflicting assumptions about:
- Whether the system is microservices or a monolith
- What technology versions to use
- Whether to implement GraphQL or REST
- How authentication works
- What image generation provider to use
- What features are in MVP scope
- How performance targets are classified

## Goal

Produce authoritative, unambiguous documentation that:
1. Uses correct architecture terminology for MVP scope
2. Pins concrete technology versions
3. Standardizes on REST JSON API
4. Documents correct authentication model
5. Uses open-weight image providers
6. Clarifies scope boundaries
7. Reclassifies performance targets as benchmarks

## Deliverables

### Modified Files

- `docs/architecture.md` — Terminology, versions, API strategy, auth, providers, queue boundary, worker access
- `docs/prd.md` — Clip editor scope, social media manager scope, performance targets, security review, issue references
- `docs/roadmap.md` — Current milestone update
- `docs/project-state.md` — Current state update
- `README.md` — Technology versions, current state

### New Files

- `specs/005-resolve-mvp-architecture-inconsistencies/spec.md`
- `specs/005-resolve-mvp-architecture-inconsistencies/plan.md`
- `specs/005-resolve-mvp-architecture-inconsistencies/test-plan.md`
- `specs/005-resolve-mvp-architecture-inconsistencies/evidence.md`

## Acceptance Criteria

- [ ] Architecture no longer describes itself as "microservices"
- [ ] Technology versions are concrete and consistent across all documents
- [ ] REST JSON API with /api/v1 is the single documented API strategy
- [ ] Authentication section describes Sanctum SPA session cookies
- [ ] OpenAIImageProvider is not listed as primary; open-weight providers are listed
- [ ] Development AI vs product AI distinction is documented
- [ ] Video AI pipeline explicitly states no generative video models
- [ ] Clip editor exclusions allow basic trimming
- [ ] Social media manager persona does not mention performance metrics
- [ ] One-click publishing semantics are documented
- [ ] Performance requirements are reclassified as benchmarks
- [ ] Third-party security review is replaced with internal review
- [ ] Issue number references are replaced with milestone references
- [ ] Queue boundary and worker database access are clarified
- [ ] Roadmap reflects current state
- [ ] No Portuguese text in any modified file
- [ ] All governance tests continue to pass

## Out of Scope

- Modifying application code (none exists)
- Adding new architectural components or services
- Changing the chosen technology stack (only pinning versions)
- Updating CI/CD configuration
- Modifying governance tests

## Dependencies

- Issue #3 (MVP Documentation Formalization): Completed
- No application code exists; documentation changes are independent
