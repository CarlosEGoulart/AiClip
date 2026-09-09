# AI Content Studio (Planned Product Vision)

AI-assisted platform for transforming long-form media into short-form social content, generating visual assets, and publishing approved clips to multiple social platforms. This vision is planned; M0 implements governance only.

The product combines:

* long-form video analysis;
* AI-assisted short clip discovery;
* automatic transcription;
* scene detection;
* vertical reframing;
* captions;
* AI image generation;
* media review;
* social account connections;
* one-click multi-platform publishing.

Target platforms include:

* YouTube Shorts;
* Instagram Reels;
* TikTok.

---

## Development Status

> This project uses strict Spec-Driven Development and processes exactly one implementation issue at a time.

### Roadmap

* [ ] M0 — Engineering Governance
* [ ] M1 — Application Foundation
* [ ] M2 — Media Storage
* [ ] M3 — Asynchronous Media Processing
* [ ] M4 — Video Understanding
* [ ] M5 — AI Clip Recommendation
* [ ] M6 — Vertical Clip Rendering
* [ ] M7 — Clip Review Experience
* [ ] M8 — AI Image Studio
* [ ] M9 — Social Connection Framework
* [ ] M10 — YouTube Publishing
* [ ] M11 — Instagram Publishing
* [ ] M12 — TikTok Publishing
* [ ] M13 — Unified One-Click Publishing
* [ ] M14 — Production Hardening

Milestones are planning boundaries.

They are NOT permission to work on multiple issues simultaneously.

---

# Architecture (Planned Target)

The following is the planned target. No application is implemented in M0.

```text
┌───────────────────────┐
│      React Web        │
└───────────┬───────────┘
            │
            ▼
┌───────────────────────┐
│      Laravel API      │
│                       │
│ Auth                  │
│ Projects              │
│ Media                 │
│ Jobs                  │
│ OAuth                 │
│ Publishing            │
└───────┬─────────┬─────┘
        │         │
        │         ▼
        │   ┌───────────────┐
        │   │  PostgreSQL   │
        │   └───────────────┘
        │
        ▼
┌───────────────────────┐
│ Python Media Worker   │
│                       │
│ FFmpeg / FFprobe      │
│ Transcription         │
│ Scene Detection       │
│ Face Tracking         │
│ Image Generation      │
│ Clip Analysis         │
└───────────────────────┘
```

---

# Technology Stack

## Backend

* Laravel
* PHP
* PostgreSQL
* Laravel queues

## Frontend

* React
* TypeScript
* Vite
* Vitest
* React Testing Library

## Media / AI

* Python
* FFmpeg
* FFprobe
* faster-whisper
* PySceneDetect
* MediaPipe / OpenCV
* provider-based image generation

## Testing

* Pest / PHPUnit
* Vitest
* React Testing Library
* pytest
* Playwright

## Infrastructure

* Docker
* Docker Compose
* GitHub Actions
* S3-compatible object storage

---

# Repository Structure

Actual governance files in this issue:

```text
.
├── AGENTS.md
├── README.md
├── docs/
│   ├── bootstrap.md
│   ├── project-state.md
│   ├── prd.md
│   ├── architecture.md
│   ├── roadmap.md
│   └── adr/
├── specs/
├── .opencode/
│   ├── agents/
│   └── skills/
├── .github/
│   └── workflows/
└── tests/
    └── governance/
```

Planned application structure (not created in M0 governance):

```text
apps/
services/
packages/
docker-compose.yml
```

---

# Development Method

This repository follows:

```text
Issue
  ↓
Specification
  ↓
Plan
  ↓
TDD RED
  ↓
TDD GREEN
  ↓
REFACTOR
  ↓
Independent Tester
  ↓
Commit
  ↓
Pull Request
  ↓
CI
  ↓
Merge
  ↓
Issue Closure
```

Only one implementation issue may be active.

See:

```text
AGENTS.md
```

for complete agent and development rules.

---

# Agent System

Development is coordinated through four agents:

```text
Orchestrator
├── Planner
├── Builder
└── Tester
```

## Planner

Defines exactly one next issue and its specification.

## Builder

Implements the active issue using TDD.

## Tester

Independently validates implementation, APIs, E2E behavior, and visual quality.

## Orchestrator

Controls GitHub workflow, CI, merge, and issue lifecycle.

---

# Branch Naming

Mandatory:

```text
@carlosegoulart/{issue_number}/{type}/{description}
```

Example:

```text
@carlosegoulart/15/feat/audio-chunk-manager
```

Allowed types:

```text
feat
fix
chore
refactor
docs
test
```

---

# Conventional Commits

Format:

```text
<type>(<scope>): <subject>
```

Allowed types:

```text
feat
fix
chore
refactor
docs
test
perf
ci
```

Example:

```text
feat(clips): generate ranked clip candidates
```

---

# Testing Policy

Every applicable feature requires:

* unit tests;
* integration/API tests;
* E2E tests;
* Playwright browser validation.

For governance-only changes with no application or interface, application unit, API, E2E, Playwright, visual, and accessibility checks are N/A with an explicit reason. Future user-facing workflows still require running-app interaction and Playwright review.

Governance validation command:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

UI changes require visual review at:

```text
390x844
768x1024
1440x900
```

Passing automated tests alone is not sufficient for approval.

---

# Language Policy

All repository content MUST be written in English.

This includes:

* source code;
* documentation;
* tests;
* issues;
* commits;
* branches;
* Pull Requests;
* comments;
* logs;
* API contracts.

---

# Current Development Cycle

Current milestone:

```text
M1 preparation
```

Current issue:

```text
#3 — Formalize MVP product definition and system architecture
```

See [docs/project-state.md](docs/project-state.md) for current status and [AGENTS.md](AGENTS.md) for complete rules.

Current goal:

```text
Formalize MVP product definition and system architecture before application scaffolding.
```

After each issue closes, update this section or `docs/project-state.md`.

---

# Documentation

Important project documents:

* [AGENTS.md](AGENTS.md)
* [docs/bootstrap.md](docs/bootstrap.md)
* [docs/prd.md](docs/prd.md) — Complete MVP product definition
* [docs/architecture.md](docs/architecture.md) — Complete system architecture
* [docs/project-state.md](docs/project-state.md) — Current project state
* [docs/roadmap.md](docs/roadmap.md) — Milestone roadmap
* [docs/adr/0001-governance-bootstrap.md](docs/adr/0001-governance-bootstrap.md) — Governance bootstrap ADR
* [docs/adr/0002-mvp-product-and-architecture.md](docs/adr/0002-mvp-product-and-architecture.md) — MVP architecture decisions ADR

Issue-specific specifications:

```text
specs/<issue>-<slug>/
├── spec.md
├── plan.md
├── test-plan.md
└── evidence.md
```

---

# Definition of Done

A change is not complete until:

* acceptance criteria are satisfied;
* TDD was followed;
* unit tests pass;
* integration tests pass when applicable;
* E2E tests pass when applicable (N/A with reason for governance-only changes);
* Playwright review passes when applicable (N/A with reason for governance-only changes);
* Tester approves;
* CI is green;
* PR is merged;
* issue is closed.

See `AGENTS.md` for the complete definition.
