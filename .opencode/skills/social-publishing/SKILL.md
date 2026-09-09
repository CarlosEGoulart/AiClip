---
name: social-publishing
description: Guide safe multi-platform publishing with confirmation and idempotency. Use when planning social OAuth, publishing, or retry handling.
---

# Social Publishing

Use only when relevant to the active task. Do not load all skills by default. This issue provides future implementation guidance only; no social integration is implemented here.

## Workflow

1. Consult current official platform and OAuth documentation. Never assume API behavior from memory.
2. Use a provider abstraction behind a common publisher interface.
3. Require authorization and explicit user confirmation of content, accounts, and selected platforms before publishing.
4. Store tokens encrypted server-side with least scopes. Keep refresh tokens secret and never send them to the frontend.
5. Ensure idempotency per destination and content operation. Record per-platform state and external identifiers.
6. Expose partial success and failure per destination.
7. Retry only eligible failed destinations with bounded retries, backoff, and rate-limit handling. Preserve successes and prevent duplicates.
8. Reconcile ambiguous timeout outcomes before retrying.
9. Handle revoked access and actionable errors with clear user guidance.
10. Use deterministic provider fakes in tests.
11. Never use unofficial browser automation to publish when an official API is required.

## Expected evidence

Confirmation record, per-platform state, partial results, retry decisions, and deterministic test handoff.
