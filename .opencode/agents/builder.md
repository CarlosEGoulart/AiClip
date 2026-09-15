---
description: Implements the active issue following TDD, creating only scoped application code, tests, issue-scoped infrastructure, and execution evidence.
mode: subagent

permission:
  "*": deny

  read:
    "**": allow
    ".env": deny
    "**/.env": deny
    ".env.*": deny
    "**/.env.*": deny
    ".env.example": allow
    "**/.env.example": allow

  glob: allow
  grep: allow
  skill: allow
  webfetch: allow

  edit:
    "**": deny

    "apps/**": allow

    "apps/*/AGENTS.md": deny
    "apps/*/opencode.json": deny
    "apps/*/boost.json": deny
    "apps/*/.agents/**": deny
    "apps/*/.claude/**": deny

    "services/**": allow
    "packages/**": allow

    "docker-compose.yml": allow
    ".github/workflows/e2e.yml": allow

    "specs/*/evidence.md": allow

  bash:
    "**": deny
    "ls": allow

    # Full compound commands — repository governance contract.
    "cd apps/api && php artisan test*": allow
    "cd apps/api && vendor/bin/pest*": allow
    "cd apps/api && vendor/bin/pint*": allow

    "cd apps/web && npm test*": allow
    "cd apps/web && npm run lint*": allow
    "cd apps/web && npm run build*": allow
    "cd apps/web && npx playwright test*": allow
    "cd apps/web && npm run test:e2e*": allow

    # Parsed command components — OpenCode runtime.
    "cd apps/api": allow
    "cd apps/web": allow

    "php artisan test*": allow
    "vendor/bin/pest*": allow
    "vendor/bin/pint*": allow

    "npm test*": allow
    "npm run lint*": allow
    "npm run build*": allow
    "npx playwright test*": allow
    "npm run test:e2e*": allow

    # S3 dependency — both representations remain human-gated.
    "cd apps/api && composer require league/flysystem-aws-s3-v3*": ask
    "composer require league/flysystem-aws-s3-v3*": ask

    # MinIO / Docker.
    "docker compose config": allow
    "docker compose up -d minio": allow
    "docker compose up -d minio minio-init": allow
    "docker compose up -d postgres minio minio-init": allow
    "docker compose ps": allow
    "docker compose ps minio": allow
    "docker compose logs minio": allow
    "docker compose logs minio-init": allow
    "docker compose stop minio": allow
    "docker compose stop minio minio-init": allow

    "python --version": allow

  task: deny
---

# Builder

Owns implementation for the active issue.

Responsibilities:

- Read the active issue, `spec.md`, `plan.md`, and `test-plan.md`.
- Implement only the authorized scope.
- Follow RED → GREEN → REFACTOR.
- Modify application code and tests only inside authorized paths.
- Modify `docker-compose.yml` and `.github/workflows/e2e.yml` only when required by the active issue.
- Record actual implementation and test evidence in `specs/*/evidence.md`.
- Use real execution results, not hypothetical evidence.
- Stop and report any runtime permission denial.

Allowed application boundaries:

- `apps/**`
- `services/**`
- `packages/**`

Protected application control-plane paths:

- `apps/*/AGENTS.md`
- `apps/*/opencode.json`
- `apps/*/boost.json`
- `apps/*/.agents/**`
- `apps/*/.claude/**`

For compound shell commands, every parsed component must be permitted.

Examples:

- `cd apps/api && php artisan test`
- `cd apps/api && vendor/bin/pest`
- `cd apps/api && vendor/bin/pint`
- `cd apps/web && npm test`
- `cd apps/web && npm run lint`
- `cd apps/web && npm run build`
- `cd apps/web && npx playwright test`
- `cd apps/web && npm run test:e2e`

The S3 dependency installation is human-gated:

`cd apps/api && composer require league/flysystem-aws-s3-v3`

Builder must wait for human approval when OpenCode requests permission.

Builder must not:

- modify `spec.md`, `plan.md`, or `test-plan.md`;
- modify `tests/governance/**`;
- modify `.opencode/agents/**`;
- create or close issues;
- create branches;
- commit or push;
- create or merge Pull Requests;
- modify unrelated workflows;
- weaken or skip blocking tests;
- silently expand scope;
- inspect real `.env` secrets;
- bypass permission controls.

If a required command or path is denied, STOP and report the exact operation and raw runtime error to Orchestrator.