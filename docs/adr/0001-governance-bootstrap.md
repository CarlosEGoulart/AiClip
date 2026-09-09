# ADR 0001: Governance Bootstrap

## Status

Accepted for issue #1.

## Context

The repository needs a minimal governance foundation before application work: roles, skills, documentation, and lightweight validation.

## Decision

Implement governance only in issue #1. Define four roles with narrow permissions and no-bypass rules. Provide six local skills. Document external design skills without vendoring them. Use a small Python governance suite plus native OpenCode loading as validation.

Application unit, API, E2E, Playwright execution, screenshots, and visual interaction are N/A for this governance-only change with explicit reasons. Future user-facing workflows still require running-app interaction and Playwright review.

Runtime models are selected at execution time through external configuration using provider and model identifiers. No ephemeral model ID is committed in role definitions. An omitted subagent model inherits its parent; Orchestrator arranges and verifies effective selection.

Shell pattern checks are workflow controls, not a sandbox against hostile agents. Inherited and global configuration affects effective behavior. Manual invocation is not restricted by task rules.

## Consequences

No Laravel, React, database, media, social, container, or deployment scaffolding is created here. The next issue requires separate explicit authorization after verified closure.
