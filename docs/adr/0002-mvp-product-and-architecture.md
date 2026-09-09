# ADR-0002: MVP Product and Architecture Decisions

## Date

2026-09-09

## Status

Accepted

## Context

Issue #1 established the governance foundation. Before application scaffolding begins, we need to formalize what the MVP actually is and how the system is architected. The current documentation contains only lightweight stubs that do not define concrete scope, service boundaries, or provider contracts.

Without formal definitions, future Builder agents will make independent assumptions about feature scope, API contracts, database schemas, and provider interfaces. These assumptions will diverge, creating inconsistencies that require destructive rework.

## Decision

We make the following architectural decisions for the MVP:

### 1. Laravel Remains the Authoritative Application Backend

Laravel handles authentication, authorization, API routing, application logic, job orchestration, and persistence. All business rules are expressed in PHP application code.

**Rationale**: Laravel provides a mature ecosystem for web applications with built-in authentication, queue management, and database abstraction. It is the natural choice for the application layer.

### 2. Python Media Worker Handles ML/Media Workloads

Computational media tasks (transcription, scene detection, clip ranking, image generation, rendering) run in a separate Python worker process, not inside PHP HTTP request processes.

**Rationale**: ML inference and media processing are CPU/GPU intensive. Running them in PHP request processes would block web requests and cause timeouts. A separate worker can scale independently.

### 3. Provider Abstractions Isolate External Service Dependencies

All external service integrations (transcription models, image generation models, clip ranking, social publishing) are behind provider interfaces. The application domain does not depend directly on a single model or platform implementation.

**Rationale**: Provider implementations may be replaced without modifying application-domain behavior. CI uses deterministic fakes instead of downloading multi-gigabyte models.

### 4. Object Storage for Media Binaries

Large video and image files are stored in object storage (e.g., S3-compatible), not in PostgreSQL.

**Rationale**: Storing large binaries in the database increases backup size, slows queries, and complicates access patterns. Object storage provides scalable, cost-effective storage for media assets.

### 5. Deterministic Fakes for CI Testing

Normal CI uses deterministic fakes for heavyweight models and external publishing systems. Real models and external APIs are tested in separate integration environments.

**Rationale**: Downloading multi-gigabyte models in CI is slow, expensive, and fragile. Deterministic fakes provide fast, reliable, repeatable tests.

### 6. Issue #2 as First Application Scaffolding Step

After documentation formalization (this issue), the next issue will scaffold the Laravel application foundation.

**Rationale**: Application scaffolding depends on knowing the architecture. This issue provides that knowledge. Scaffolding should start with the smallest useful increment: Laravel project initialization, database connection, and basic routing.

## Consequences

### Positive

- Future Builder agents have authoritative boundaries to implement within
- Provider abstractions allow model/platform replacement without domain changes
- Object storage scales independently of the database
- CI remains fast and deterministic
- Python worker can scale independently of the web tier

### Negative

- Additional complexity from service decomposition
- Object storage requires infrastructure configuration
- Provider abstractions add indirection
- Python worker requires separate deployment and monitoring

### Risks

- Over-engineering the architecture before implementation begins
- Provider interfaces may need adjustment as real models are integrated
- Object storage costs may be significant for large media files

## References

- Issue #3: https://github.com/CarlosEGoulart/AiClip/issues/3
- `docs/prd.md`: MVP product definition
- `docs/architecture.md`: System architecture
- `docs/roadmap.md`: Development milestones
- `AGENTS.md`: Governance rules
