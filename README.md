# AiClip — AI Content Studio

AiClip is an AI-assisted creator platform that transforms long-form video into short-form clips, generates images from text prompts, and publishes approved content to social platforms. The platform reduces the time and effort required for content repurposing by automating clip discovery, visual asset generation, and multi-platform publishing.

## Development Status

| Milestone | Status |
|-----------|--------|
| M0 — Engineering Governance | Completed |
| M1 — Application Foundation | In progress |
| M2–M14 — Product Features | Planned |

M1 delivered: Laravel/React/PostgreSQL foundation, health vertical slice, foundation stabilization. Next M1 slice: authentication and user management.

## Architecture

```text
React 19 (apps/web)          Laravel 13 API (apps/api)
├── TypeScript                ├── PHP 8.3
├── Vite 8                   ├── PostgreSQL 16
├── Vitest 5                 ├── Pest
├── React Testing Library    ├── Laravel Sanctum (planned)
└── Playwright E2E           └── Laravel Queues (planned)

Python Media Worker (planned)
├── FFmpeg / FFprobe
├── Transcription
├── Scene detection
└── Image generation
```

Laravel is the authoritative application backend. Heavy ML/media processing runs outside PHP HTTP request processes. The React frontend communicates with the API via REST through a Vite dev proxy (local) or same-origin routing (production).

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13, PHP 8.3, PostgreSQL 16 |
| Frontend | React 19, TypeScript, Vite 8 |
| Testing | Pest (backend), Vitest (frontend), Playwright (E2E) |
| Infrastructure | Docker Compose, GitHub Actions |
| Agent System | OpenCode with Planner/Builder/Tester/Orchestrator |

## Repository Structure

```text
AGENTS.md                     Agent governance rules (normative)
README.md                     This file
apps/api/                     Laravel API (health route, Pest suite)
apps/web/                     React frontend (health page, Vitest + Playwright)
docker-compose.yml            Local PostgreSQL 16 service
docs/                         Product, architecture, roadmap, decisions, project state
specs/                        Per-issue spec.md, plan.md, test-plan.md, evidence.md
tests/governance/             Governance contract tests
.opencode/agents/             Agent role definitions (Planner, Builder, Tester, Orchestrator)
```

## Development Method

All development follows:

- **Spec-Driven Development** — every issue has a specification, plan, and test plan
- **Test-Driven Development** — RED → GREEN → REFACTOR cycle is mandatory
- **GitHub Flow** — feature branches, pull requests, CI validation, merge
- **Conventional Commits** — `<type>(<scope>): <subject>` format
- **Strict Issue Linearity** — one active implementation issue at a time
- **Independent Testing** — Tester reviews Builder work independently

## Agent System

Four specialized agents with isolated responsibilities:

```text
Orchestrator — lifecycle control, Git/GitHub operations
├── Planner — defines what to build (specs, plans)
├── Builder — implements the active issue (TDD)
└── Tester — independent quality gate (approve/reject)
```

Agent definitions live in `.opencode/agents/`. The root `AGENTS.md` is the normative authority; agent configs must not contradict it.

## Branch Naming

```text
@carlosegoulart/{issue}/{type}/{description}
```

Types: `feat`, `fix`, `chore`, `refactor`, `docs`, `test`.

## Conventional Commits

```text
<type>(<scope>): <subject>
```

Types: `feat`, `fix`, `chore`, `refactor`, `docs`, `test`, `perf`, `ci`.

## Testing Policy

| Layer | Tool | Command |
|-------|------|---------|
| Backend | Pest | `php artisan test --compact` |
| Frontend | Vitest | `npm test` |
| E2E | Playwright | `npm run test:e2e` |
| Lint | oxlint | `npm run lint` |
| Governance | Python unittest | `python -m unittest discover -s tests/governance` |

Playwright is mandatory for user-facing workflows. Required viewports: 390x844, 768x1024, 1440x900.

## Design Skills

Two external design skills are documented but not installed:

- `design-taste-frontend` — UI taste guidance (when UI work begins)
- `web-design-guidelines` — Vercel design review (when UI work begins)

Load skills only when relevant. Do not inject every skill into every context.

## Language Policy

All repository content is in English. Portuguese is forbidden inside repository artifacts.

## Local Development

Start PostgreSQL:

```sh
docker compose up -d --wait postgres
```

Backend (apps/api):

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan test --compact
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend (apps/web):

```sh
npm ci
npm test
npm run lint
npm run build
npm run dev
```

Open http://127.0.0.1:5173 for the health page.

E2E (no prestarted apps needed):

```sh
cd apps/web
npx playwright install --with-deps chromium
npm run test:e2e
```

## Documentation Index

| File | Purpose |
|------|---------|
| `AGENTS.md` | Normative agent governance rules |
| `docs/project-state.md` | Current architecture, capabilities, decisions |
| `docs/prd.md` | Product requirements document |
| `docs/architecture.md` | System architecture and design |
| `docs/roadmap.md` | Milestone plan |
| `docs/bootstrap.md` | Orchestrator startup guide |
| `docs/adr/` | Architecture decision records |

## Definition of Done

An issue is done when all applicable items are satisfied:

- Issue exists with specification
- Branch naming is valid
- TDD cycle demonstrated (RED, GREEN, REFACTOR)
- Unit tests pass
- Integration tests pass
- E2E tests pass (when applicable)
- Playwright validation passes (when applicable)
- Tester approved
- CI green
- PR merged
- Issue closed

Return to NO_ACTIVE_ISSUE and wait for explicit authorization before starting another issue.
