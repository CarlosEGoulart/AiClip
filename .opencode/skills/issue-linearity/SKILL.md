---
name: issue-linearity
description: Enforce one active implementation issue with legal lifecycle transitions. Use when creating issues, branches, commits, PRs, or checking issue state.
---

# Issue Linearity

Use only when relevant to the active task. Do not load all skills by default.

## Workflow

1. Inspect the active GitHub issue, open Pull Requests, current branch, and `docs/project-state.md` before starting work.
2. Confirm exactly one implementation issue is active. Parallel implementation is forbidden.
3. Verify the real GitHub issue exists before creating its branch. The branch name uses the real issue number.
4. Follow the legal lifecycle in order: issue creation, branch creation, specification, verified RED, GREEN, REFACTOR, independent Tester approval, commit, push, issue-linked PR, green CI, merge, verified closure.
5. Respect forbidden transitions such as opening a new issue before closure, opening a PR before RED, or merging with failed CI.
6. Keep every change issue-linked. Unrelated work is recorded for future Planner evaluation, not implemented immediately.
7. On Tester rejection, return to the same active issue through Orchestrator to Builder to Tester. Do not create a new issue for defects in the active issue.
8. After verified closure, return to `NO_ACTIVE_ISSUE`, report completion, and stop. Do not start another issue without explicit authorization.

## Expected evidence

Record the active issue reference, branch, lifecycle position, and closure verification in `evidence.md` or the PR handoff.
