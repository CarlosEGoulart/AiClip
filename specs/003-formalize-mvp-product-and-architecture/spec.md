# Specification — Issue #3: Formalize MVP Product Definition and System Architecture

## Title

Formalize MVP Product Definition and System Architecture

## Description

Issue #1 established governance. Issue #3 must formalize what the MVP actually is before any application scaffolding begins. The current `docs/prd.md` and `docs/architecture.md` are intentional stubs that say "planned" without defining concrete scope. If Builder begins M1 without a formalized product definition and architecture, it will invent system boundaries, API contracts, database schemas, and feature scope that later require destructive rework.

This issue updates two existing documentation files (`docs/prd.md` and `docs/architecture.md`) and creates one new ADR. It defines the MVP user journeys, feature scope, service boundaries, data model, provider abstractions, security model, and deployment topology. It explicitly excludes all application implementation.

## Problem

Without formal product and architecture definitions, every future Builder will make independent assumptions about:

- What features belong in MVP
- How services are decomposed
- What the data model looks like
- How providers are abstracted
- What security controls are required

These assumptions will diverge, creating inconsistencies that require destructive rework when discovered.

## Goal

Create authoritative documentation that:

1. Defines exactly what MVP includes and excludes
2. Establishes the system architecture and service boundaries
3. Documents provider abstraction contracts
4. Records key architectural decisions
5. Prevents future agents from inventing system boundaries

## Deliverables

### Modified Files

- `docs/prd.md` — Complete MVP product definition
- `docs/architecture.md` — Complete system architecture
- `docs/project-state.md` — Updated with current milestone and next goal
- `README.md` — Updated links and current state

### New Files

- `docs/adr/0002-mvp-product-and-architecture.md` — Architectural decision record

## Acceptance Criteria

- [ ] `docs/prd.md` defines at least: 3 user personas, 5 core workflows, MVP feature list with clear in/out boundaries, non-functional requirements, and success metrics
- [ ] `docs/architecture.md` defines at least: 4 service boundaries (Laravel, React, Media Worker, infrastructure), data flow between services, provider abstraction contracts for AI capabilities, database entity overview (entities and relationships, not DDL), security architecture, and deployment model
- [ ] `docs/adr/0002-mvp-product-and-architecture.md` exists and records the key decisions made in this issue
- [ ] `docs/project-state.md` is updated with current milestone and next goal
- [ ] `README.md` links to formalized documents and reflects current state
- [ ] No application code, database schema, API implementation, UI component, media processing, or social integration is created
- [ ] All documentation is written in English
- [ ] All documents are internally consistent with no contradictions

## Out of Scope

- Laravel application scaffolding or initialization
- React application scaffolding or initialization
- PostgreSQL schema creation or migration files
- Media worker implementation or FFmpeg integration
- Social OAuth implementation or API integration
- Docker or Docker Compose configuration
- CI/CD pipeline changes beyond documentation
- Any production code of any kind
- Database DDL or migration files
- API route definitions or controller implementation
- UI component implementation

## Security Considerations

- Architecture document must define the security model: OAuth token storage, API secret handling, user data isolation, and network boundaries between services
- PRD must define authentication requirements and authorization model
- Provider abstraction must isolate AI model credentials from application logic
- Architecture must specify that refresh tokens never reach the frontend

## UX Considerations

- PRD must define the primary user journeys and key screen flows at a conceptual level
- Architecture must specify how the React frontend communicates with the Laravel backend
- MVP scope must be realistic for a solo developer / small team
- Mobile responsiveness requirements should be documented if applicable

## Test Scenarios

1. Verify `docs/prd.md` contains required sections (personas, workflows, MVP boundary, NFRs, success metrics)
2. Verify `docs/architecture.md` contains required sections (service boundaries, data flow, provider contracts, database overview, security, deployment)
3. Verify `docs/adr/0002-mvp-product-and-architecture.md` exists and records decisions
4. Verify `docs/project-state.md` is updated
5. Verify `README.md` links are valid
6. Verify no application code was introduced (git diff shows only documentation changes)
7. Verify internal consistency: PRD features match architecture services, architecture matches roadmap milestones
8. Verify all documents are in English

## Dependencies

- Issue #1 (governance bootstrap): COMPLETED
- No other dependencies
