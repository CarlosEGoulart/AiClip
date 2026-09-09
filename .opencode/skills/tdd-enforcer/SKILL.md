---
name: tdd-enforcer
description: Require spec-first RED-GREEN-REFACTOR with assertion-based evidence. Use when writing tests, implementing behavior, or recording TDD results.
---

# TDD Enforcer

Use only when relevant to the active task. Do not load all skills by default.

## Workflow

1. Read the specification and acceptance criteria before writing tests.
2. Write or update a behavior or governance contract test before implementation content.
3. Run the test and confirm valid assertion-based RED: it fails because required behavior is missing.
4. Distinguish valid RED from environment failures such as missing dependencies, syntax errors, broken fixtures, or unavailable services. Those do not count.
5. Implement the minimal change needed for GREEN. Run the relevant tests.
6. Optionally refactor without changing behavior, then rerun tests. All tests must remain green.
7. Record concise evidence: command, exit status, failing assertions for RED, passing result for GREEN, and refactor rerun.
8. Do not weaken, disable, or skip required assertions to obtain GREEN.
9. Preserve applicable unit, integration, and E2E coverage. Governance-only changes document application test applicability as N/A with a reason.
10. Require independent Tester review. Builder confidence is not approval.

## Expected evidence

Concise RED, GREEN, and refactor records with commands and outcomes, owned by the executing role in `evidence.md`.
