# Current Architecture

MVP product definition and system architecture formalized. Planned stack is Laravel, React with TypeScript, PostgreSQL, Python media worker with FFmpeg, Playwright, Docker, and GitHub Actions. No application is deployed.

# Completed Capabilities

Issue #1 governance foundation merged into master: four OpenCode roles, six local skills, planning documents, governance tests, and lightweight CI.

Issue #3 documentation formalization: complete MVP product definition, system architecture, and architectural decision record.

# Important Decisions

Strict issue linearity with one active implementation issue. TDD RED before implementation content. Independent Tester review. English-only repository content.

Laravel remains authoritative application backend. Python media worker handles ML/media workloads outside PHP. Provider abstractions isolate external service dependencies. Object storage for media binaries. Deterministic fakes for CI testing.

# Known Limitations

No running application exists, so application unit, API, E2E, Playwright, visual, and accessibility interaction are N/A for this issue. Runtime model selection and native role invocation require Orchestrator arrangement and fresh restarts.

# Current Milestone

M1 preparation.

# Next Architectural Goal

Application scaffolding: initialize Laravel and React projects, set up development environment, create basic API endpoints and database migrations.