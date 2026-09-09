---
name: token-efficient-context
description: Minimize context with targeted reads and isolated handoffs. Use when gathering repository context or preparing agent handoffs.
---

# Token Efficient Context

Use only when relevant to the active task. Do not load all skills by default.

## Workflow

1. Start with `docs/project-state.md`, the active issue, active spec, plan, test plan, and relevant changed files.
2. Search before reading entire files. Read only relevant sections or small targeted excerpts.
3. Summarize large logs. Inspect targeted diffs before inspecting the entire repository.
4. Exclude `vendor/`, `node_modules/`, `build/`, `dist/`, full Git history, all closed issues, unrelated documentation, and prior conversations unless necessary.
5. Keep subagent contexts isolated with concise handoffs. Do not preload every skill or the full history.
6. Record concise evidence and handoffs. Avoid duplicating the full policy into every document.

## Expected evidence

Short handoffs listing sources inspected, excerpts used, and deliberately excluded context.
